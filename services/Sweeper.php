<?php

namespace humhub\modules\slackBridge\services;

use humhub\modules\slackBridge\models\SlackEvent;

/**
 * Reprend les événements restés en plan.
 *
 * Deux appelants : le cron horaire (automatique) et `yii slack-bridge/retry`
 * (à la main, quand on vient de réparer la cause). Même code, pour que ce qui
 * est testé à la main soit ce qui tourne tout seul.
 */
class Sweeper
{
    /**
     * @return array{traites: int, publies: int, ecartes: int, echecs: int}
     */
    public static function run(int $limit = 100, int $graceSeconds = 300): array
    {
        // Le délai de grâce évite de doubler le traitement en ligne d'un
        // événement reçu il y a quelques secondes et encore en cours.
        $pending = SlackEvent::findRetryable($graceSeconds, $limit);

        $importer = new MessageImporter();
        $counts = ['traites' => 0, 'publies' => 0, 'ecartes' => 0, 'echecs' => 0];

        foreach ($pending as $record) {
            $importer->process($record);
            $counts['traites']++;

            match ($record->status) {
                SlackEvent::STATUS_POSTED => $counts['publies']++,
                SlackEvent::STATUS_SKIPPED => $counts['ecartes']++,
                default => $counts['echecs']++,
            };
        }

        return $counts;
    }
}
