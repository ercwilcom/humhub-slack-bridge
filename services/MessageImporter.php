<?php

namespace humhub\modules\slackBridge\services;

use humhub\modules\comment\models\Comment;
use humhub\modules\comment\Module as CommentModule;
use humhub\modules\content\models\Content;
use humhub\modules\slackBridge\models\SilentComment;
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
 * Deux formes, et la seconde n'existe que par la première : un message de
 * premier niveau devient un POST, une réponse de fil devient un COMMENTAIRE
 * sous le post de son message d'ouverture. Une réponse dont l'ouverture n'a pas
 * été republiée n'a donc nulle part où aller, et s'écarte.
 *
 * Tout ce qui est publié porte l'HEURE DU MESSAGE SLACK, jamais celle de
 * l'import — voir `backdate()`.
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
    /**
     * Plus jamais émis : une réponse de fil devient un commentaire. Le motif
     * reste nommé parce que le registre en porte des centaines, et qu'une page
     * d'admin qui afficherait `thread_reply` brut ferait passer une décision
     * d'hier pour une panne d'aujourd'hui.
     */
    public const SKIP_THREAD_REPLY = 'thread_reply';
    /** Réponse à un message qui, lui, n'a jamais été republié : rien à commenter. */
    public const SKIP_PARENT_NOT_MIRRORED = 'parent_not_mirrored';
    /** Le post d'accueil n'accepte pas de commentaire (verrouillé, archivé, module retiré). */
    public const SKIP_COMMENTS_CLOSED = 'comments_closed';
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
     * Un seul effet, désormais — tout le reste du chemin est identique, ce qui
     * est la raison d'être de ce drapeau plutôt que d'un second importeur : ce
     * qui est repris se crée en SILENCE. Ni activité, ni notification, ni
     * poussée temps réel, ni remontée du post en tête du fil.
     *
     * Un message d'il y a deux mois qui notifie cent sept personnes ment sur ce
     * qu'il est : ceci n'est pas en train d'arriver.
     *
     * ⚠️ **La DATE, elle, ne dépend plus de ce drapeau.** Tout ce que le miroir
     * publie porte l'heure du message Slack, repris ou non — voir `backdate()`.
     * C'est ce qui rend un message rattrapé trois heures plus tard par le
     * balayage lisible à sa vraie place, au lieu de prétendre venir d'arriver.
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
                    // `thread_broadcast` : une réponse de fil que son auteur a
                    // aussi renvoyée dans le canal. Elle reste une réponse — le
                    // chemin ci-dessous la reconnaît à son `thread_ts` et en
                    // fera un commentaire, pas un second post.
                case 'thread_broadcast':
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

        // Une réponse de fil porte un thread_ts différent de son propre ts. Elle
        // ne devient pas un post : elle devient un COMMENTAIRE sous le post du
        // message qui a ouvert le fil. C'est la seule forme qui garde à la
        // conversation la sienne — un fil de Slack recopié en cinq posts
        // indépendants dans un espace est illisible, et les réponses y
        // arriveraient séparées de ce à quoi elles répondent.
        $threadTs = (string) ($event['thread_ts'] ?? '');
        if ($threadTs !== '' && $threadTs !== $ts) {
            $this->handleReply($record, $event, $threadTs);
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
        $this->backdate($post, $ts);

        $record->markPosted((int) $post->id);
    }

    /**
     * Une réponse de fil devient un COMMENTAIRE sous le post du message qui a
     * ouvert le fil.
     *
     * ── CE QUE LA RÈGLE DU CANAL NE FILTRE PAS ICI ──────────────────────────
     * La condition « avec image » ne porte que sur ce qui OUVRE une
     * conversation : c'est un filtre sur ce qui devient un post, pas une
     * exigence de forme sur ce qu'on se répond ensuite. Une réponse sans photo
     * sous une annonce photographiée est exactement la conversation attendue.
     * Le topic non plus ne se repose pas : il appartient au post, et un
     * commentaire n'en porte pas.
     *
     * ── SANS PARENT, PAS DE COMMENTAIRE ─────────────────────────────────────
     * Si le message d'ouverture n'a jamais été republié — auteur non apparié,
     * règle « avec image », canal branché après coup, rétractation depuis —, il
     * n'y a rien ici sous quoi accrocher la réponse. On l'écarte, sous son
     * propre motif pour que le décompte de la page d'admin ne mélange pas les
     * deux : fabriquer un post d'accueil publierait au nom de quelqu'un un
     * message que le miroir avait écarté à dessein.
     */
    private function handleReply(SlackEvent $record, array $event, string $threadTs): void
    {
        $channelId = (string) ($event['channel'] ?? '');
        $ts = (string) ($event['ts'] ?? '');

        // Rejeu tardif d'un événement déjà traité : le commentaire est là, on ne
        // le republie pas. Même raisonnement que pour un post, à ceci près que
        // c'est le commentaire qu'on vérifie — le post, lui, existe forcément.
        $existing = SlackMessage::findByTs($channelId, $ts);
        if ($existing !== null && $existing->comment_id !== null
            && Comment::findOne(['id' => $existing->comment_id]) !== null) {
            $record->markPosted((int) $existing->post_id);
            return;
        }

        $rule = $channelId !== '' ? SlackChannel::findOne(['channel_id' => $channelId, 'enabled' => true]) : null;
        if ($rule === null) {
            $record->markSkipped(self::SKIP_CHANNEL_NOT_MAPPED);
            return;
        }

        $parent = SlackMessage::findByTs($channelId, $threadTs);
        $post = $parent !== null ? Post::findOne(['id' => $parent->post_id]) : null;
        if ($post === null) {
            // « Pas encore » et « jamais » ne se ressemblent qu'ici : le
            // registre les distingue. Si le message d'ouverture attend encore
            // son tour — processus mort en route, échec technique pas épuisé —,
            // la réponse est mise en échec plutôt qu'écartée, et le balayage
            // horaire les reprendra tous deux, dans l'ordre. Un écart, lui, ne
            // se rejoue jamais : le prononcer trop tôt perdrait la réponse en
            // silence.
            if ($this->parentStillComing($channelId, $threadTs)) {
                $record->markFailed('Message d\'ouverture pas encore republié.');
                return;
            }

            $record->markSkipped(self::SKIP_PARENT_NOT_MIRRORED);
            return;
        }

        $author = $this->resolveAuthor((string) $event['user']);
        if ($author === null) {
            $record->markSkipped(self::SKIP_AUTHOR_NOT_MATCHED);
            return;
        }

        // Le verrou des commentaires, l'archivage, la permission de commenter
        // dans ce conteneur : la question se pose POUR L'AUTEUR, pas pour le
        // processus. Un admin qui a verrouillé les commentaires d'un post
        // republié a pris une décision ; la passerelle ne la contourne pas.
        if (!$this->asUser($author, fn(): bool => $this->canComment($post))) {
            $record->markSkipped(self::SKIP_COMMENTS_CLOSED);
            return;
        }

        $message = $this->formatter->toMarkdown((string) ($event['text'] ?? ''));
        $files = $this->attachableFiles($this->slackFiles($event));

        if ($message === '' && $files === []) {
            $record->markSkipped(self::SKIP_EMPTY);
            return;
        }

        $comment = $this->asUser($author, function () use ($post, $author, $message): ?Comment {
            // La forme silencieuse pour une reprise d'historique : le cœur n'a
            // pas d'équivalent de `silentContentCreation` pour un commentaire.
            $comment = $this->historical ? new SilentComment() : new Comment();
            $comment->message = $message;
            // setPolymorphicRelation() plutôt que object_model/object_id à la
            // main : c'est le comportement du cœur qui pose le couple, et le
            // formulaire de HumHub ne fait rien d'autre.
            $comment->setPolymorphicRelation($post);
            $comment->created_by = (int) $author->id;

            return $comment->save() ? $comment : null;
        });

        if ($comment === null) {
            $record->markFailed('Enregistrement du commentaire refusé.');
            return;
        }

        SlackMessage::record($channelId, $ts, (int) $post->id, $parent->space_id, (int) $author->id, (int) $comment->id);

        if ($files !== []) {
            $this->attachments->attachAll($comment, $files, $author);
        }

        // Après les pièces jointes, pour la même raison que sur un post.
        $this->backdateComment($comment, $ts);

        $record->markPosted((int) $post->id);
    }

    /**
     * Le message d'ouverture est-il encore en chemin ?
     *
     * Vrai tant que son événement est au registre sans verdict et avec des
     * tentatives en réserve. Faux s'il a été écarté, s'il a épuisé ses essais,
     * ou s'il n'est jamais passé par ici.
     */
    private function parentStillComing(string $channelId, string $threadTs): bool
    {
        return SlackEvent::find()
            ->where(['channel_id' => $channelId, 'message_ts' => $threadTs])
            ->andWhere(['status' => [SlackEvent::STATUS_PENDING, SlackEvent::STATUS_FAILED]])
            ->andWhere(['<', 'attempts', SlackEvent::MAX_ATTEMPTS])
            ->exists();
    }

    /**
     * Le post d'accueil accepte-t-il un commentaire de l'utilisateur courant ?
     *
     * Le module `comment` est un module du cœur, mais il se désactive : sans
     * lui, il n'y a pas de commentaire possible, et une réponse s'écarte au lieu
     * d'échouer en boucle au balayage horaire.
     */
    private function canComment(Post $post): bool
    {
        $module = Yii::$app->getModule('comment');

        return $module instanceof CommentModule && $module->canComment($post);
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
     * Une modification dans Slack réécrit ici ce que le message avait produit —
     * son post, ou son commentaire si c'était une réponse de fil.
     *
     * Sans ça, une correction resterait sans effet sur ce qui est publié — et
     * puisque tout le canal est recopié, la seule voie de rattrapage serait
     * l'admin du Hub. Le texte seul est repris : les pièces jointes d'un
     * message déjà publié ne changent pas dans Slack.
     *
     * La date de création ne bouge pas, l'heure de modification si : c'est
     * exactement ce que HumHub montre par son crayon « modifié », et ici il dit
     * vrai — le texte a bien changé après coup.
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

        $text = $this->formatter->toMarkdown((string) ($message['text'] ?? ''));
        if ($text === '') {
            $record->markSkipped(self::SKIP_EMPTY);
            return;
        }

        $author = User::findOne(['id' => $mapping->author_id]);

        if ($mapping->comment_id !== null) {
            $comment = Comment::findOne(['id' => $mapping->comment_id]);
            if ($comment === null) {
                // Effacé côté Hub entre-temps — par un admin, ou avec le post
                // qui le portait. Le lien ne vaut plus rien.
                SlackMessage::forget($channelId, $ts);
                $record->markSkipped(self::SKIP_UNKNOWN_MESSAGE);
                return;
            }

            $saved = $this->asUser($author, function () use ($comment, $text): bool {
                $comment->message = $text;

                return $comment->save();
            });

            if (!$saved) {
                $record->markFailed('Mise à jour du commentaire refusée.');
                return;
            }

            $record->markPosted((int) $mapping->post_id);
            return;
        }

        $post = Post::findOne(['id' => $mapping->post_id]);
        if ($post === null) {
            // Supprimé côté Hub entre-temps : le lien ne vaut plus rien.
            SlackMessage::forget($channelId, $ts);
            $record->markSkipped(self::SKIP_UNKNOWN_MESSAGE);
            return;
        }

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
     * Une suppression dans Slack retire ici ce que le message avait produit.
     *
     * C'est la contrepartie indispensable du miroir intégral : effacer chez soi
     * doit suffire à effacer partout, sans passer par un admin.
     *
     * Retirer le message qui a ouvert un fil emporte ses réponses : HumHub
     * supprime les commentaires avec leur contenu. C'est le bon comportement —
     * ce qui reste sinon est une conversation sans ce à quoi elle répond — mais
     * il laisse derrière lui des liens qui ne désignent plus rien, d'où le
     * `forgetByPost()` plutôt qu'un simple `forget()`.
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

        if ($mapping->comment_id !== null) {
            $comment = Comment::findOne(['id' => $mapping->comment_id]);
            if ($comment !== null) {
                // Un commentaire n'a pas de corbeille chez HumHub : delete() est
                // déjà définitif, contrairement au post juste en dessous.
                $author = User::findOne(['id' => $mapping->author_id]);
                $this->asUser($author, fn() => $comment->delete());
            }

            SlackMessage::forget($channelId, $ts);
            $record->markPosted(null);
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

        SlackMessage::forgetByPost((int) $mapping->post_id);
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
     * Le post porte l'heure du message Slack, pas celle de l'import.
     *
     * ── POUR TOUT CE QUI PASSE, PAS SEULEMENT POUR UNE REPRISE ──────────────
     * En temps réel l'écart n'est que de quelques secondes, et c'est justement
     * ce qui rend la question invisible : le jour où le webhook a été coupé une
     * heure, où le balayage horaire rattrape la panne, où une pièce jointe de
     * trente mégaoctets a retenu l'import — alors la seule date que le fil
     * puisse afficher sans mentir est celle de Slack. Une passerelle qui date
     * ses copies de l'heure de la copie n'est pas un miroir.
     *
     * ── TROIS COLONNES, ET DEUX SONT CELLES QU'ON OUBLIE ────────────────────
     * `created_at` dit ce qui s'affiche sous le nom de l'auteur ;
     * `stream_sort_date` décide de la PLACE dans le fil — n'en poser qu'une
     * donne un post daté de mai qui trône en tête de l'espace, le pire des deux
     * mondes parce qu'il a l'air correct ; `updated_at` enfin décide du crayon
     * « modifié », que HumHub affiche dès qu'il diffère de `created_at`. Sans
     * lui, TOUT ce que la passerelle republie se présente comme retouché après
     * coup, alors que personne n'y a touché.
     *
     * `updateAttributes()` et pas `save()` : on écrit ces colonnes-là et rien
     * d'autre, sans réveiller les comportements d'horodatage du cœur, qui
     * remettraient l'heure courante par-dessus.
     *
     * Une vraie modification venue de Slack, elle, repasse par `save()` : le
     * crayon apparaît alors, et il dit vrai.
     */
    private function backdate(Post $post, string $ts, bool $keepUpdated = false): void
    {
        $stamp = self::stamp($ts);

        $dates = ['created_at' => $stamp, 'stream_sort_date' => $stamp];
        if (!$keepUpdated) {
            $dates['updated_at'] = $stamp;
        }

        $post->content->updateAttributes($dates);
        $post->updateAttributes($keepUpdated ? ['created_at' => $stamp] : ['created_at' => $stamp, 'updated_at' => $stamp]);
    }

    /**
     * Même chose pour un commentaire, avec une colonne de moins : un
     * commentaire ne décide pas de la place du post dans le fil — c'est le cœur
     * qui s'en charge quand la réponse arrive pour de vrai, et la forme
     * silencieuse de la reprise qui s'en abstient.
     *
     * `updated_at` compte autant qu'ici : `Comment::isUpdated()` compare les
     * deux dates, et un fil entier de commentaires marqués « modifié » raconte
     * une histoire qui n'a pas eu lieu.
     */
    private function backdateComment(Comment $comment, string $ts, bool $keepUpdated = false): void
    {
        $stamp = self::stamp($ts);

        $comment->updateAttributes($keepUpdated ? ['created_at' => $stamp] : ['created_at' => $stamp, 'updated_at' => $stamp]);
    }

    /**
     * Repose les dates d'un miroir DÉJÀ publié, à partir du ts qui l'a produit.
     *
     * Le rattrapage de `slack-bridge/redate`, pour ce qui est passé quand la
     * date de l'import faisait foi. Il n'y a pas d'autre appelant : le chemin
     * normal date à la publication.
     *
     * ⚠️ **Un message que Slack a vu modifier garde son heure de modification.**
     * Sans ce garde, le rattrapage effacerait le crayon « modifié » de tout ce
     * qui a VRAIMENT été corrigé — il ne réparerait pas un mensonge, il en
     * poserait un autre. Le registre est la seule trace qui distingue les deux.
     *
     * @return bool false si le post ou le commentaire n'existe plus.
     */
    public function restamp(SlackMessage $mirror): bool
    {
        $keepUpdated = $this->wasEditedInSlack($mirror);

        if ($mirror->comment_id !== null) {
            $comment = Comment::findOne(['id' => $mirror->comment_id]);
            if ($comment === null) {
                return false;
            }
            $this->backdateComment($comment, $mirror->message_ts, $keepUpdated);

            return true;
        }

        $post = Post::findOne(['id' => $mirror->post_id]);
        if ($post === null) {
            return false;
        }
        $this->backdate($post, $mirror->message_ts, $keepUpdated);

        return true;
    }

    /** Le registre a-t-il traité une modification de ce message ? */
    private function wasEditedInSlack(SlackMessage $mirror): bool
    {
        return SlackEvent::find()
            ->where(['channel_id' => $mirror->channel_id, 'message_ts' => $mirror->message_ts])
            ->andWhere(['status' => SlackEvent::STATUS_POSTED])
            ->andWhere(['like', 'payload', 'message_changed'])
            ->exists();
    }

    /**
     * Un ts Slack est « 1787229101.378509 » — des secondes Unix avec une
     * fraction qui distingue deux messages de la même seconde. MySQL n'en a que
     * faire ; c'est la seconde qui nous intéresse.
     */
    private static function stamp(string $ts): string
    {
        return date('Y-m-d H:i:s', (int) (float) $ts);
    }

    /**
     * Exécute l'opération sous l'identité du membre, puis rend la main.
     *
     * HumHub lit l'utilisateur courant dans ses crochets d'après-sauvegarde —
     * flux d'activité, notifications, analyse des mentions. Sans identité, ces
     * crochets travaillent sur un invité. Le rétablissement importe surtout au
     * balayage console, qui enchaîne plusieurs auteurs dans un même processus.
     */
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
