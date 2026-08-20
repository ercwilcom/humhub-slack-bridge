<?php

namespace humhub\modules\slackBridge\services;

use humhub\modules\content\models\Content;
use humhub\modules\slackBridge\models\SlackChannel;
use humhub\modules\slackBridge\models\SlackEvent;
use humhub\modules\slackBridge\models\SlackMessage;
use humhub\modules\post\models\Post;
use humhub\modules\space\models\Space;
use humhub\modules\topic\models\Topic;
use humhub\modules\user\models\User;
use Throwable;
use Yii;

/**
 * Le cœur de la passerelle : ce qu'un événement Slack devient ici.
 *
 * Chaque événement finit dans un des trois états du registre — republié,
 * volontairement écarté, ou en échec technique. La distinction compte : un
 * écart est définitif et normal (auteur inconnu du Hub, canal non branché),
 * un échec sera repris au balayage horaire.
 *
 * Les motifs d'écart sont des étiquettes stables, pas des phrases : la page
 * d'admin les compte et les traduit.
 */
class MessageImporter
{
    public const SKIP_NOT_A_MESSAGE = 'not_a_message';
    public const SKIP_SUBTYPE = 'unsupported_subtype';
    public const SKIP_BOT = 'bot_message';
    public const SKIP_THREAD_REPLY = 'thread_reply';
    public const SKIP_CHANNEL_NOT_MAPPED = 'channel_not_mapped';
    public const SKIP_SPACE_MISSING = 'space_missing';
    public const SKIP_NO_IMAGE = 'no_image';
    public const SKIP_NO_AUTHOR = 'no_author';
    public const SKIP_AUTHOR_NOT_MATCHED = 'author_not_matched';
    public const SKIP_EMPTY = 'empty_message';
    public const SKIP_UNKNOWN_MESSAGE = 'unknown_message';

    /**
     * L'étiquette d'origine, apposée à TOUT ce qui vient de Slack.
     *
     * Elle dit au lecteur ce que le fil ne dit pas : personne n'a choisi de
     * publier ça ici. C'est aussi ce qui rend le miroir repérable après coup —
     * sans elle, un post republié et un post écrit dans le Hub sont
     * indistinguables, et la question « d'où vient ce message ? » n'a plus de
     * réponse consultable.
     */
    public const ORIGIN_TOPIC = 'Via Slack';

    /**
     * Reprise d'historique plutôt que message du jour (voir `Backfiller`).
     *
     * Deux effets, et deux seulement — tout le reste du chemin est identique,
     * ce qui est la raison d'être de ce drapeau plutôt que d'un second
     * importeur :
     *
     *   1. le post porte la DATE du message Slack, pas celle de l'import ;
     *   2. il se crée en SILENCE : ni activité, ni notification.
     *
     * Les deux disent la même chose sous deux formes : ceci n'est pas en train
     * d'arriver. Un post d'il y a deux mois qui remonte au sommet d'un tableau
     * de bord, ou qui notifie cent sept personnes, ment sur ce qu'il est.
     */
    public bool $historical = false;

    private SlackApi $api;
    private MessageFormatter $formatter;
    private AttachmentImporter $attachments;

    public function __construct(?SlackApi $api = null)
    {
        $this->api = $api ?? new SlackApi();
        $this->formatter = new MessageFormatter($this->api);
        $this->attachments = new AttachmentImporter($this->api);
    }

    /**
     * Traite un événement du registre et l'y marque. N'émet jamais d'exception :
     * appelée depuis le webhook, elle ne doit pas pouvoir renvoyer une erreur à
     * Slack — qui rejouerait alors l'événement.
     */
    public function process(SlackEvent $record): void
    {
        $record->countAttempt();

        try {
            $event = $record->getPayload()['event'] ?? [];

            if (($event['type'] ?? '') !== 'message') {
                $record->markSkipped(self::SKIP_NOT_A_MESSAGE);
                return;
            }

            switch ((string) ($event['subtype'] ?? '')) {
                case '':
                    // `file_share` : un message accompagné d'un fichier. C'est un
                    // message ordinaire du point de vue du miroir.
                case 'file_share':
                    $this->handleNew($record, $event);
                    break;
                case 'message_changed':
                    $this->handleEdit($record, $event);
                    break;
                case 'message_deleted':
                    $this->handleDelete($record, $event);
                    break;
                default:
                    // Arrivées/départs de canal, épinglages, messages de bot…
                    // Rien de tout ça n'a de sens dans le fil d'un espace.
                    $record->markSkipped(self::SKIP_SUBTYPE);
            }
        } catch (Throwable $e) {
            Yii::error('slack-bridge : échec sur ' . $record->event_id . ' — ' . $e->getMessage(), 'slack-bridge');
            $record->markFailed($e->getMessage());
        }
    }

    private function handleNew(SlackEvent $record, array $event): void
    {
        $channelId = (string) ($event['channel'] ?? '');
        $ts = (string) ($event['ts'] ?? '');

        if (!empty($event['bot_id']) || ($event['user'] ?? '') === '') {
            $record->markSkipped(empty($event['bot_id']) ? self::SKIP_NO_AUTHOR : self::SKIP_BOT);
            return;
        }

        // Une réponse de fil porte un thread_ts différent de son propre ts. Le
        // choix retenu est de ne republier que les messages de premier niveau.
        if (!empty($event['thread_ts']) && $event['thread_ts'] !== $ts) {
            $record->markSkipped(self::SKIP_THREAD_REPLY);
            return;
        }

        // Rejeu tardif d'un événement déjà traité : le registre a pu être marqué
        // après coup, mais le message, lui, est déjà là. On ne republie pas.
        $existing = SlackMessage::findByTs($channelId, $ts);
        if ($existing !== null && Post::findOne(['id' => $existing->post_id]) !== null) {
            $record->markPosted($existing->post_id);
            return;
        }

        $rule = $channelId !== '' ? SlackChannel::findOne(['channel_id' => $channelId, 'enabled' => true]) : null;
        if ($rule === null) {
            $record->markSkipped(self::SKIP_CHANNEL_NOT_MAPPED);
            return;
        }

        // Les fichiers annoncés par Slack, indépendamment du rapatriement : la
        // condition « avec image » porte sur ce que le message contient, pas sur
        // ce qu'on a choisi de recopier.
        $slackFiles = $this->slackFiles($event);

        // Filtre de portée avant résolution d'identité : un message hors sujet
        // pour ce canal n'est pas un manque, et le vérifier ne coûte aucun appel
        // à l'API Slack.
        if ($rule->require_image && !$this->hasImage($slackFiles)) {
            $record->markSkipped(self::SKIP_NO_IMAGE);
            return;
        }

        $author = $this->resolveAuthor((string) $event['user']);
        if ($author === null) {
            // L'écart le plus fréquent, et le plus consultable : la page d'admin
            // le compte pour que le miroir partiel ne reste pas invisible.
            $record->markSkipped(self::SKIP_AUTHOR_NOT_MATCHED);
            return;
        }

        // Le babillard se publie sur le profil de qui écrit : la destination
        // n'est donc connue qu'une fois l'auteur identifié.
        $space = null;
        if ($rule->getTargetsAuthorProfile()) {
            $container = $author;
        } else {
            $space = Space::findOne(['id' => $rule->space_id]);
            if ($space === null) {
                $record->markSkipped(self::SKIP_SPACE_MISSING);
                return;
            }
            $container = $space;
        }

        $message = $this->formatter->toMarkdown((string) ($event['text'] ?? ''));
        $files = $this->attachableFiles($slackFiles);

        if ($message === '' && $files === []) {
            $record->markSkipped(self::SKIP_EMPTY);
            return;
        }

        $post = $this->asUser($author, function () use ($container, $author, $message, $files, $rule): ?Post {
            $post = new Post($container, Content::VISIBILITY_PUBLIC);
            // Un post sans texte mais avec pièce jointe est légitime (une photo
            // seule) ; le scénario le dit au validateur.
            if ($message === '' && $files !== []) {
                $post->scenario = Post::SCENARIO_HAS_FILES;
            }
            // Se pose AVANT l'enregistrement : c'est `afterSave` du cœur qui
            // fabrique l'activité et les notifications, et il lit ce drapeau.
            // L'oublier ne casse rien de visible à l'import — ça se paie en
            // trois cents notifications, une fois, sans reprise possible.
            $post->silentContentCreation = $this->historical;
            $post->message = $message;
            $post->content->created_by = (int) $author->id;

            if (!$post->save()) {
                return null;
            }

            // Le topic reste dans l'impersonation : la pose d'une étiquette
            // consulte les permissions du conteneur et réindexe le contenu.
            //
            // Les DEUX d'un coup, et ce n'est pas un raccourci d'écriture :
            // `Topic::attach()` remplace la liste entière au lieu de l'allonger.
            // Poser « Via Slack » après le topic de la règle effacerait celui-ci
            // — en silence, et seulement sur les canaux qui en ont un.
            $this->applyTopics($post, array_filter([$rule->topic_name, self::ORIGIN_TOPIC]));

            return $post;
        });

        if ($post === null) {
            $record->markFailed('Enregistrement du post refusé.');
            return;
        }

        SlackMessage::record($channelId, $ts, (int) $post->id, $space?->id, (int) $author->id);

        if ($files !== []) {
            $this->attachments->attachAll($post, $files, $author);
        }

        // APRÈS les pièces jointes, à dessein : attacher un fichier touche au
        // contenu, et une date posée avant se ferait réécrire par le cœur.
        if ($this->historical) {
            $this->backdate($post, $ts);
        }

        $record->markPosted((int) $post->id);
    }

    /**
     * Appose des topics HumHub au post, en les créant au besoin.
     *
     * ── POURQUOI UN TABLEAU, ET PAS UN APPEL PAR TOPIC ──────────────────────
     * `Topic::attach()` commence par SUPPRIMER toutes les relations existantes,
     * puis pose ce qu'on lui donne (« Clear all relations and append them
     * again », dans le cœur). Deux appels de suite ne font donc pas deux
     * étiquettes : le second efface le premier. La panne serait invisible
     * là où il n'y a qu'un topic, et ne mordrait que sur le seul canal qui en
     * porte deux — le genre d'erreur qu'on ne voit qu'un mois plus tard.
     *
     * Les topics sont propres à leur conteneur : « Agenda culturel Outremont »
     * dans Communications est un autre objet que le même nom ailleurs. On
     * cherche donc dans le conteneur du post avant de créer. « Via Slack »
     * existera par conséquent une fois par Space alimenté ; c'est le même mot
     * pour qui lit, et trois lignes en base.
     *
     * On passe des objets Topic et non la forme `_add:<nom>` qu'utilise le
     * formulaire : celle-ci vérifie la permission AddTopic de l'utilisateur
     * courant, ce qui n'a pas de sens pour une republication automatique.
     *
     * `Topic::attach()` attend le **Content**, pas le Post : sur un
     * ContentActiveRecord, `getContent()` est l'accesseur de relation et rend
     * une requête, pas un modèle. Tout HumHub l'appelle avec `$record->content`.
     *
     * Un topic qui échoue ne doit pas emporter le post : il est déjà publié.
     *
     * @param string[] $names
     */
    public function applyTopics(Post $post, array $names): void
    {
        if ($names === []) {
            return;
        }

        try {
            $containerId = (int) $post->content->contentcontainer_id;
            $topics = [];

            foreach ($names as $name) {
                $topic = Topic::findByName($name, $containerId)->one();
                if ($topic === null) {
                    $topic = new Topic(['name' => $name, 'contentcontainer_id' => $containerId]);
                    if (!$topic->save()) {
                        Yii::warning('slack-bridge : topic « ' . $name . ' » refusé — ' . implode(' ; ', $topic->getFirstErrors()), 'slack-bridge');
                        continue;
                    }
                }
                $topics[] = $topic;
            }

            if ($topics !== []) {
                Topic::attach($post->content, $topics);
            }
        } catch (Throwable $e) {
            Yii::warning('slack-bridge : topics non apposés au post ' . $post->id . ' — ' . $e->getMessage(), 'slack-bridge');
        }
    }

    /**
     * Une modification dans Slack réécrit le post ici.
     *
     * Sans ça, une correction resterait sans effet sur ce qui est publié — et
     * puisque tout le canal est recopié, la seule voie de rattrapage serait
     * l'admin du Hub. Le texte seul est repris : les pièces jointes d'un
     * message déjà publié ne changent pas dans Slack.
     */
    private function handleEdit(SlackEvent $record, array $event): void
    {
        $channelId = (string) ($event['channel'] ?? '');
        $message = $event['message'] ?? [];
        $ts = (string) ($message['ts'] ?? '');

        $mapping = $ts !== '' ? SlackMessage::findByTs($channelId, $ts) : null;
        if ($mapping === null) {
            // Jamais republié — auteur non apparié, canal non branché à
            // l'époque… Il n'y a rien à corriger.
            $record->markSkipped(self::SKIP_UNKNOWN_MESSAGE);
            return;
        }

        $post = Post::findOne(['id' => $mapping->post_id]);
        if ($post === null) {
            // Supprimé côté Hub entre-temps : le lien ne vaut plus rien.
            SlackMessage::forget($channelId, $ts);
            $record->markSkipped(self::SKIP_UNKNOWN_MESSAGE);
            return;
        }

        $text = $this->formatter->toMarkdown((string) ($message['text'] ?? ''));
        if ($text === '') {
            $record->markSkipped(self::SKIP_EMPTY);
            return;
        }

        $author = User::findOne(['id' => $mapping->author_id]);
        $saved = $this->asUser($author, function () use ($post, $text): bool {
            $post->message = $text;

            return $post->save();
        });

        if (!$saved) {
            $record->markFailed('Mise à jour du post refusée.');
            return;
        }

        $record->markPosted((int) $post->id);
    }

    /**
     * Une suppression dans Slack retire le post ici.
     *
     * C'est la contrepartie indispensable du miroir intégral : effacer chez soi
     * doit suffire à effacer partout, sans passer par un admin.
     */
    private function handleDelete(SlackEvent $record, array $event): void
    {
        $channelId = (string) ($event['channel'] ?? '');
        $ts = (string) ($event['deleted_ts'] ?? '');

        $mapping = $ts !== '' ? SlackMessage::findByTs($channelId, $ts) : null;
        if ($mapping === null) {
            $record->markSkipped(self::SKIP_UNKNOWN_MESSAGE);
            return;
        }

        $post = Post::findOne(['id' => $mapping->post_id]);
        if ($post !== null) {
            // hardDelete() et non delete() : chez HumHub, delete() est une mise
            // à la corbeille — le contenu reste en base et un admin peut le
            // restaurer. Ça conviendrait à un membre qui efface son propre post ;
            // pas ici. Personne n'a choisi de publier ce message sur le Hub, une
            // rédaction automatique l'y a mis ; sa rétractation doit donc être
            // entière, sans copie qui traîne dans la corbeille.
            $author = User::findOne(['id' => $mapping->author_id]);
            $this->asUser($author, fn() => $post->hardDelete());
        }

        SlackMessage::forget($channelId, $ts);
        $record->markPosted(null);
    }

    /**
     * L'auteur Slack, retrouvé ici par son courriel.
     *
     * Le compte doit être actif : republier au nom d'un compte désactivé ferait
     * réapparaître dans le fil quelqu'un qui a quitté la coop.
     */
    private function resolveAuthor(string $slackUserId): ?User
    {
        $email = $this->api->getUserEmail($slackUserId);
        if ($email === null) {
            return null;
        }

        return User::findOne(['email' => $email, 'status' => User::STATUS_ENABLED]);
    }

    /**
     * Les fichiers annoncés par Slack dans le message, tels quels.
     *
     * @return array<int, array<string, mixed>>
     */
    private function slackFiles(array $event): array
    {
        $files = $event['files'] ?? [];

        return is_array($files) ? array_values(array_filter($files, 'is_array')) : [];
    }

    /**
     * Ceux qu'on rapatrie effectivement — rien si le rapatriement est désactivé.
     *
     * @param array<int, array<string, mixed>> $slackFiles
     * @return array<int, array<string, mixed>>
     */
    private function attachableFiles(array $slackFiles): array
    {
        /** @var \humhub\modules\slackBridge\Module $module */
        $module = Yii::$app->getModule('slack-bridge');

        return $module->getAttachFiles() ? $slackFiles : [];
    }

    /**
     * Le message porte-t-il une image ?
     *
     * On regarde le type MIME et non l'extension du nom : Slack le renseigne, et
     * une petite annonce photographiée depuis un téléphone peut arriver sans
     * extension exploitable.
     *
     * @param array<int, array<string, mixed>> $slackFiles
     */
    private function hasImage(array $slackFiles): bool
    {
        foreach ($slackFiles as $file) {
            if (stripos((string) ($file['mimetype'] ?? ''), 'image/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exécute l'opération sous l'identité du membre, puis rend la main.
     *
     * HumHub lit l'utilisateur courant dans ses crochets d'après-sauvegarde —
     * flux d'activité, notifications, analyse des mentions. Sans identité, ces
     * crochets travaillent sur un invité. Le rétablissement importe surtout au
     * balayage console, qui enchaîne plusieurs auteurs dans un même processus.
     */
    /**
     * Le post prend la date du message Slack, pas celle de l'import.
     *
     * DEUX colonnes, et la seconde est celle qu'on oublie : `created_at` dit ce
     * qui s'affiche sous le nom de l'auteur, `stream_sort_date` décide de la
     * PLACE dans le fil. N'en poser qu'une donne un post daté de mai qui trône
     * en tête du Space — le pire des deux mondes, parce qu'il a l'air correct.
     *
     * `updateAttributes()` et pas `save()` : on écrit ces colonnes-là et rien
     * d'autre, sans réveiller les comportements d'horodatage du cœur, qui
     * remettraient l'heure courante par-dessus.
     */
    private function backdate(Post $post, string $ts): void
    {
        // Un ts Slack est « 1787229101.378509 » — des secondes Unix avec une
        // fraction qui distingue deux messages de la même seconde. MySQL n'en a
        // que faire ; c'est la seconde qui nous intéresse.
        $stamp = date('Y-m-d H:i:s', (int) (float) $ts);

        $post->content->updateAttributes([
            'created_at' => $stamp,
            'stream_sort_date' => $stamp,
        ]);
        $post->updateAttributes(['created_at' => $stamp]);
    }

    private function asUser(?User $user, callable $fn)
    {
        if ($user === null) {
            return $fn();
        }

        $previous = Yii::$app->user->identity;
        Yii::$app->user->setIdentity($user);
        try {
            return $fn();
        } finally {
            Yii::$app->user->setIdentity($previous);
        }
    }
}
