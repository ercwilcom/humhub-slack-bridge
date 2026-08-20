<?php

namespace humhub\modules\slackBridge\services;

use Generator;
use humhub\modules\slackBridge\models\SlackChannel;
use humhub\modules\slackBridge\models\SlackEvent;
use humhub\modules\slackBridge\models\SlackMessage;
use Throwable;
use Yii;

/**
 * La reprise de l'historique — ce que la passerelle refusait de faire jusqu'ici.
 *
 * ── CE QUI REND CE FICHIER COURT, ET C'EST VOULU ────────────────────────────
 * Rien ici ne décide de rien. Un message d'archive traverse EXACTEMENT le même
 * chemin qu'un message arrivé par le webhook : on lui fabrique un événement au
 * registre, et `MessageImporter` fait le reste — la règle du canal, le filtre
 * « avec image », l'appariement de l'auteur, le formatage, les pièces jointes,
 * le topic. Une seconde implémentation aurait dérivé de la première au premier
 * correctif, et c'est précisément sur les vieux messages que personne n'irait
 * vérifier.
 *
 * ── LE PLAFOND EST CHEZ SLACK, PAS ICI ──────────────────────────────────────
 * Mesuré le 2026-08-20 sur l'espace de la coop : `conversations.history` ne
 * rend rien de plus vieux que 90 jours (limite du forfait gratuit). Ce n'est
 * pas une pagination à pousser plus loin, c'est un mur — et il GLISSE : ce qui
 * n'est pas repris aujourd'hui sera hors de portée demain. D'où une reprise
 * qu'on peut relancer sans risque plutôt qu'un grand soir.
 *
 * ── DEUX CHOSES QUE LE TEMPS RÉEL N'A PAS BESOIN DE SAVOIR ──────────────────
 * `MessageImporter::$historical` porte les deux, et c'est tout ce qui distingue
 * une reprise d'un message du jour :
 *
 *   1. **La date.** Sans elle, trois cents posts arrivent estampillés
 *      aujourd'hui, dans le tableau de bord de cent sept personnes, et le fil
 *      du Space raconte que la coop a tout écrit le même après-midi.
 *   2. **Le silence.** Aucune activité, aucune notification : personne n'a à
 *      être prévenu de ce qui a été dit il y a deux mois.
 */
class Backfiller
{
    /** Au-delà, on s'arrête : une pagination qui ne finit pas est un bogue, pas un gros canal. */
    private const MAX_PAGES = 100;

    private const PAGE_SIZE = 200;

    private SlackApi $api;
    private MessageImporter $importer;

    public function __construct(?SlackApi $api = null)
    {
        $this->api = $api ?? new SlackApi();
        $this->importer = new MessageImporter($this->api);
        $this->importer->historical = true;
    }

    /**
     * Reprend l'historique d'un canal selon SA règle.
     *
     * @param string|null   $oldest   horodatage Slack (« 1750000000 ») ou null pour tout ce qui reste accessible
     * @param int           $limit    0 = sans limite ; sinon arrête après N reprises effectives
     * @param callable|null $progress fn(string $ts, string $issue): void — appelé à chaque message traité
     *
     * @return array<string, int> vus, deja, repris, ecartes, echecs
     */
    public function run(
        SlackChannel $rule,
        ?string $oldest = null,
        int $limit = 0,
        bool $dryRun = false,
        ?callable $progress = null,
    ): array {
        $counts = ['vus' => 0, 'deja' => 0, 'repris' => 0, 'ecartes' => 0, 'echecs' => 0];

        foreach ($this->messages($rule->channel_id, $oldest) as $message) {
            $counts['vus']++;

            $ts = (string) ($message['ts'] ?? '');
            if ($ts === '') {
                continue;
            }

            // Le garde-fou qui rend la commande rejouable : un message déjà
            // republié n'est même pas inscrit au registre. C'est ce qui permet
            // de relancer après avoir apparié quelqu'un, sans rien dupliquer.
            if (SlackMessage::findByTs($rule->channel_id, $ts) !== null) {
                $counts['deja']++;
                $progress && $progress($ts, 'deja');
                continue;
            }

            if ($dryRun) {
                // On ne PRÉDIT pas l'issue : elle dépend de l'appariement de
                // l'auteur, que seul l'import résout. Annoncer « repris » ici
                // pour découvrir « écarté » à l'exécution serait pire que se
                // taire. Ce chiffre est donc « à tenter », pas « à publier ».
                $counts['repris']++;
                $progress && $progress($ts, 'a_tenter');
                continue;
            }

            $issue = $this->importOne($rule->channel_id, $ts, $message);
            $counts[$issue] = ($counts[$issue] ?? 0) + 1;
            $progress && $progress($ts, $issue);

            if ($limit > 0 && $counts['repris'] >= $limit) {
                break;
            }
        }

        return $counts;
    }

    /**
     * Un message d'archive → un événement du registre → l'import ordinaire.
     *
     * L'`event_id` est préfixé `backfill:` pour deux raisons : il ne peut pas
     * entrer en collision avec un identifiant de Slack (les leurs commencent
     * par `Ev`), et le registre garde ainsi la trace de ce qui vient de la
     * reprise plutôt que du direct — utile le jour où un écart surprend.
     */
    private function importOne(string $channelId, string $ts, array $message): string
    {
        // La forme d'un `event_callback`, parce que c'est ce que l'importeur
        // lit. Le canal n'est pas dans le message rendu par l'historique : il
        // est dans la requête, donc on le remet ici.
        $payload = [
            'type' => 'event_callback',
            'event' => $message + ['type' => 'message', 'channel' => $channelId],
        ];

        try {
            $record = SlackEvent::claim('backfill:' . $channelId . ':' . $ts, $payload);
        } catch (Throwable $e) {
            Yii::error('slack-bridge : reprise refusée au registre (' . $ts . ') — ' . $e->getMessage(), 'slack-bridge');
            return 'echecs';
        }

        if ($record === null) {
            // Déjà inscrit lors d'un passage précédent, mais sans message
            // republié — donc écarté ou en échec. On ne le rejoue pas ici :
            // c'est le travail du balayage, qui sait distinguer les deux.
            return 'deja';
        }

        $this->importer->process($record);

        // `process()` n'émet jamais d'exception : c'est le registre qui porte le
        // verdict, et c'est lui qu'on lit.
        $record->refresh();

        return match ($record->status) {
            SlackEvent::STATUS_POSTED => 'repris',
            SlackEvent::STATUS_SKIPPED => 'ecartes',
            default => 'echecs',
        };
    }

    /**
     * L'historique du canal, du PLUS ANCIEN au plus récent.
     *
     * Slack rend l'inverse (le dernier d'abord) ; on retourne chaque page parce
     * que l'ordre de création décide de l'ordre d'affichage à date égale, et
     * qu'un fil reconstitué à l'envers se lit mal — deux messages postés dans
     * la même minute se retrouveraient inversés.
     *
     * @return Generator<int, array>
     */
    private function messages(string $channelId, ?string $oldest): Generator
    {
        $pages = [];
        $cursor = '';
        $n = 0;

        do {
            $page = $this->api->history($channelId, $oldest, $cursor, self::PAGE_SIZE);
            $pages[] = $page['messages'];
            $cursor = $page['cursor'];
            $n++;
        } while ($cursor !== '' && $n < self::MAX_PAGES);

        // Les pages viennent de la plus récente à la plus ancienne, et chacune
        // est elle-même à l'envers : les deux niveaux se retournent.
        foreach (array_reverse($pages) as $page) {
            foreach (array_reverse($page) as $message) {
                yield $message;
            }
        }
    }
}
