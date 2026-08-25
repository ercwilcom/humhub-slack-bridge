<?php

namespace humhub\modules\slackBridge\services;

use humhub\modules\slackBridge\models\SlackEvent;
use humhub\modules\slackBridge\models\SlackMessage;

/**
 * Repasse sur ce que le miroir avait écarté, quand l'état du monde a changé.
 *
 * ── CE QUE CE SERVICE N'EST PAS ─────────────────────────────────────────────
 * Ce n'est pas le balayage horaire, qui reprend les ÉCHECS techniques. Un écart
 * n'est pas un échec : c'est la conséquence d'un état — cette personne n'a pas
 * de compte apparié, ce message répond à un message jamais republié, ce canal
 * n'avait pas de règle. Rejouer ça toutes les heures serait s'entêter contre
 * une réponse qui ne changera pas toute seule.
 *
 * C'est un GESTE : quelqu'un vient d'apparier un auteur, d'ajouter une règle,
 * de retirer une condition — et demande qu'on regarde à nouveau.
 *
 * ── ET IL NE PARLE PAS À SLACK ──────────────────────────────────────────────
 * Le registre garde le message d'origine dans le payload de son événement.
 * Rattraper trois mois d'écarts ne coûte donc aucun appel d'historique, et
 * marche même au-delà du mur des 90 jours : ce que la reprise ne peut plus
 * DEMANDER, le registre l'a déjà.
 */
class Replayer
{
    /**
     * Les motifs qui dépendent d'un état modifiable — les seuls qu'il vaille la
     * peine de reconsidérer.
     *
     * Sont exclus, à dessein : les messages de robot, les messages vides, les
     * types non repris, les auteurs déclarés « à ne jamais apparier ». Aucun
     * geste d'admin ne changera jamais ce verdict-là ; les rejouer serait du
     * bruit qui grossirait le décompte sans rien produire.
     */
    public const REPLAYABLE = [
        MessageImporter::SKIP_AUTHOR_NOT_MATCHED,
        MessageImporter::SKIP_PARENT_NOT_MIRRORED,
        MessageImporter::SKIP_CHANNEL_NOT_MAPPED,
        MessageImporter::SKIP_SPACE_MISSING,
        MessageImporter::SKIP_NO_IMAGE,
        MessageImporter::SKIP_COMMENTS_CLOSED,
        // Les réponses de fil écartées AVANT que les fils ne traversent : leur
        // message est au registre, et il n'a jamais été republié.
        MessageImporter::SKIP_THREAD_REPLY,
    ];

    private SlackApi $api;

    public function __construct(?SlackApi $api = null)
    {
        $this->api = $api ?? new SlackApi();
    }

    /**
     * @param string|null $slackUserId ne repasser que sur les messages de cet auteur
     * @param int         $limit       plafond d'événements examinés
     * @param string|null $reason      un motif précis, au lieu des motifs rattrapables
     *
     * ── `$reason`, ET POURQUOI IL EXISTE ────────────────────────────────────
     * La liste ci-dessus recense les verdicts qu'un GESTE D'ADMIN peut lever.
     * Il en existe un autre genre : ceux qu'un changement de CODE lève. Le jour
     * où le miroir s'est mis à porter les réponses de fil, tous les
     * `thread_reply` du registre sont devenus rattrapables — et le jour où il a
     * reconnu un sous-type de plus, ce sont des `unsupported_subtype`.
     *
     * Les mettre dans la liste permanente serait faux : rejouer les
     * arrivées de canal et les messages effacés à chaque rattrapage est du
     * bruit, et personne ne relit un décompte qui compte du bruit. Nommer le
     * motif à la main est le bon geste — rare, délibéré, et il dit lui-même
     * pourquoi on le fait.
     *
     * @return array{vus: int, repris: int, ecartes: int, echecs: int, restants: int}
     */
    public function run(?string $slackUserId = null, int $limit = 1000, ?string $reason = null): array
    {
        $counts = ['vus' => 0, 'repris' => 0, 'ecartes' => 0, 'echecs' => 0, 'restants' => 0];

        $importer = new MessageImporter($this->api);
        // Un rattrapage n'est pas un événement du jour : ni activité, ni
        // notification, ni remontée en tête de fil. La date, elle, vient du
        // message comme partout ailleurs.
        $importer->historical = true;

        // Par `message_ts` croissant : une réponse de fil porte un ts plus grand
        // que le message qui l'ouvre, donc l'ordre chronologique suffit à ce que
        // chaque parent soit republié avant ses réponses. Sans lui, rattraper un
        // auteur laisserait les réponses à ses messages écartées une fois de
        // plus, pour la raison qu'on vient justement de lever.
        $motifs = $reason !== null ? [$reason] : self::REPLAYABLE;

        $events = SlackEvent::find()
            ->where(['status' => SlackEvent::STATUS_SKIPPED])
            ->andWhere(['reason' => $motifs])
            ->orderBy(['message_ts' => SORT_ASC])
            ->limit($limit)
            ->all();

        foreach ($events as $event) {
            if ($slackUserId !== null && (string) ($event->getPayload()['event']['user'] ?? '') !== $slackUserId) {
                continue;
            }

            // Republié depuis, par une reprise d'historique ou un rejeu :
            // l'événement porte un verdict périmé, pas un message manquant.
            if ($event->message_ts !== null
                && SlackMessage::findByTs((string) $event->channel_id, (string) $event->message_ts) !== null) {
                continue;
            }

            $counts['vus']++;
            $importer->process($event);
            $event->refresh();

            match ($event->status) {
                SlackEvent::STATUS_POSTED => $counts['repris']++,
                SlackEvent::STATUS_SKIPPED => $counts['ecartes']++,
                default => $counts['echecs']++,
            };
        }

        // Ce qui n'a pas été regardé faute de place : le dire, plutôt que de
        // laisser croire que le rattrapage a tout vu.
        $counts['restants'] = max(0, SlackEvent::find()
            ->where(['status' => SlackEvent::STATUS_SKIPPED])
            ->andWhere(['reason' => $motifs])
            ->count() - count($events));

        return $counts;
    }
}
