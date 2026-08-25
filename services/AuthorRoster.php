<?php

namespace humhub\modules\slackBridge\services;

use humhub\modules\slackBridge\models\SlackAuthor;
use humhub\modules\slackBridge\models\SlackEvent;
use humhub\modules\slackBridge\models\SlackMessage;
use humhub\modules\user\models\User;
use Throwable;

/**
 * Qui écrit dans Slack sans qu'on sache à qui l'attribuer ici.
 *
 * ── LA LISTE SE DÉDUIT DU REGISTRE, ELLE NE SE TIENT PAS ────────────────────
 * Rien n'enregistre « les gens à apparier » : la question se relit à chaque
 * fois dans les événements écartés. Une liste tenue à part se serait mise à
 * mentir au premier appariement fait ailleurs — et surtout, elle n'aurait
 * connu que les gens vus depuis sa création.
 *
 * Le décompte est ce qui rend la page utile : « 14 messages » et « 1 message »
 * ne demandent pas le même effort d'identification.
 */
class AuthorRoster
{
    /** Au-delà, on ne remonte pas : la page d'admin doit rester consultable. */
    private const MAX_EVENTS = 2000;

    private SlackApi $api;

    public function __construct(?SlackApi $api = null)
    {
        $this->api = $api ?? new SlackApi();
    }

    /**
     * Les auteurs Slack dont les messages ont été écartés faute de compte
     * apparié, du plus prolifique au moins.
     *
     * `decision` dit où en est chacun : `null` = rien de décidé, un entier =
     * apparié à ce compte, `false` = déclaré à ne jamais apparier.
     *
     * @return array<int, array{slack_user_id: string, messages: int, name: string, email: string, decision: int|false|null}>
     */
    public function unpaired(): array
    {
        $events = SlackEvent::find()
            ->where(['status' => SlackEvent::STATUS_SKIPPED])
            ->andWhere(['reason' => [MessageImporter::SKIP_AUTHOR_NOT_MATCHED, MessageImporter::SKIP_AUTHOR_IGNORED]])
            ->orderBy(['received_at' => SORT_DESC])
            ->limit(self::MAX_EVENTS)
            ->all();

        $counts = [];
        foreach ($events as $event) {
            $slackUserId = (string) ($event->getPayload()['event']['user'] ?? '');
            if ($slackUserId === '') {
                continue;
            }

            // Republié depuis — la personne a été appariée, et son message est
            // passé par un AUTRE événement du même message (le direct et la
            // reprise en inscrivent chacun un). L'événement restant porte un
            // verdict périmé ; le compter ferait réapparaître dans la liste des
            // choses à faire quelqu'un dont tout est déjà là.
            if ($event->message_ts !== null
                && SlackMessage::findByTs((string) $event->channel_id, (string) $event->message_ts) !== null) {
                continue;
            }

            $counts[$slackUserId] = ($counts[$slackUserId] ?? 0) + 1;
        }

        arsort($counts);

        $out = [];
        foreach ($counts as $slackUserId => $messages) {
            $decided = SlackAuthor::findOne(['slack_user_id' => $slackUserId]);
            // Le profil Slack est en cache une heure ; l'API n'est donc pas
            // sollicitée à chaque affichage de la page. Injoignable, on retombe
            // sur le nom retenu au moment de la décision — la ligne reste
            // lisible plutôt que de se réduire à un identifiant.
            try {
                $profile = $this->api->getUserProfile($slackUserId);
            } catch (Throwable $e) {
                $profile = ['name' => '', 'email' => ''];
            }

            $out[] = [
                'slack_user_id' => $slackUserId,
                'messages' => $messages,
                'name' => $profile['name'] !== '' ? $profile['name'] : (string) ($decided->label ?? ''),
                'email' => $profile['email'],
                'decision' => $decided === null ? null : ($decided->user_id === null ? false : (int) $decided->user_id),
            ];
        }

        return $out;
    }

    /** Les comptes du Hub qu'on peut proposer, du plus récemment actif au moins. */
    public function candidates(): array
    {
        return User::find()
            ->where(['status' => User::STATUS_ENABLED])
            ->orderBy(['username' => SORT_ASC])
            ->all();
    }
}
