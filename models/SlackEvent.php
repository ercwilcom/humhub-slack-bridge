<?php

namespace humhub\modules\slackBridge\models;

use humhub\components\ActiveRecord;
use Throwable;
use Yii;
use yii\db\Exception as DbException;
use yii\helpers\Json;

/**
 * Un événement Slack reçu, et ce qu'on en a fait.
 *
 * @property string $event_id
 * @property string|null $channel_id
 * @property string|null $message_ts
 * @property string $status
 * @property string|null $reason
 * @property int|null $post_id
 * @property string $payload
 * @property int $attempts
 * @property string $received_at
 * @property string|null $processed_at
 */
class SlackEvent extends ActiveRecord
{
    /** Reçu, pas encore traité — ou traitement interrompu en cours de route. */
    public const STATUS_PENDING = 'pending';
    /** Republié dans le Hub. */
    public const STATUS_POSTED = 'posted';
    /** Volontairement laissé de côté (auteur non apparié, canal non branché…). */
    public const STATUS_SKIPPED = 'skipped';
    /** A échoué pour une raison technique ; le balayage horaire y repassera. */
    public const STATUS_FAILED = 'failed';

    /** Au-delà, on cesse de réessayer : c'est cassé, pas passager. */
    public const MAX_ATTEMPTS = 5;

    public static function tableName(): string
    {
        return 'slack_bridge_event';
    }

    /**
     * Enregistre l'événement, ou renonce s'il est déjà connu.
     *
     * Slack rejoue un événement jusqu'à trois fois tant qu'il n'a pas obtenu son
     * 200 en moins de trois secondes. C'est ici que le doublon est arrêté, et
     * c'est l'unicité de la clé primaire qui l'arrête — pas un SELECT préalable,
     * qui laisserait passer deux rejeux simultanés.
     *
     * @return self|null null si l'événement était déjà là.
     */
    public static function claim(string $eventId, array $payload): ?self
    {
        $event = new self();
        $event->event_id = $eventId;
        $event->channel_id = self::extractChannel($payload);
        $event->message_ts = self::extractTs($payload);
        $event->status = self::STATUS_PENDING;
        $event->payload = Json::encode($payload);
        $event->attempts = 0;
        $event->received_at = date('Y-m-d H:i:s');

        try {
            // insert() direct plutôt que save() : on veut que la violation de
            // clé remonte comme exception, pas comme une erreur de validation
            // silencieuse.
            Yii::$app->db->createCommand()->insert(self::tableName(), [
                'event_id' => $event->event_id,
                'channel_id' => $event->channel_id,
                'message_ts' => $event->message_ts,
                'status' => $event->status,
                'payload' => $event->payload,
                'attempts' => 0,
                'received_at' => $event->received_at,
            ])->execute();
        } catch (DbException $e) {
            // 23000 = violation de contrainte d'intégrité : le doublon attendu.
            // Toute autre erreur est un vrai problème et doit remonter.
            if (($e->errorInfo[0] ?? null) === '23000') {
                return null;
            }
            throw $e;
        }

        $event->setIsNewRecord(false);

        return $event;
    }

    /** Le payload d'origine, tel que reçu. */
    public function getPayload(): array
    {
        try {
            $decoded = Json::decode($this->payload, true);
        } catch (Throwable $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function markPosted(?int $postId): void
    {
        $this->finish(self::STATUS_POSTED, null, $postId);
    }

    public function markSkipped(string $reason): void
    {
        $this->finish(self::STATUS_SKIPPED, $reason, null);
    }

    public function markFailed(string $reason): void
    {
        $this->finish(self::STATUS_FAILED, mb_substr($reason, 0, 191), null);
    }

    private function finish(string $status, ?string $reason, ?int $postId): void
    {
        $this->status = $status;
        $this->reason = $reason;
        $this->post_id = $postId;
        $this->processed_at = date('Y-m-d H:i:s');
        // updateAttributes : pas de validation, pas d'événements — on écrit un
        // journal, et un journal ne doit jamais faire échouer ce qu'il journalise.
        $this->updateAttributes(['status', 'reason', 'post_id', 'processed_at']);
    }

    public function countAttempt(): void
    {
        $this->attempts = (int) $this->attempts + 1;
        $this->updateAttributes(['attempts']);
    }

    /**
     * Ce que le balayage horaire doit reprendre : traitements interrompus et
     * échecs pas encore épuisés. Le délai de grâce évite de doubler le
     * traitement en ligne d'un événement reçu il y a quelques secondes.
     */
    public static function findRetryable(int $graceSeconds = 300, int $limit = 100): array
    {
        return self::find()
            ->where(['status' => [self::STATUS_PENDING, self::STATUS_FAILED]])
            ->andWhere(['<', 'attempts', self::MAX_ATTEMPTS])
            ->andWhere(['<', 'received_at', date('Y-m-d H:i:s', time() - $graceSeconds)])
            ->orderBy(['received_at' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    private static function extractChannel(array $payload): ?string
    {
        $event = $payload['event'] ?? [];
        $channel = $event['channel'] ?? null;

        return is_string($channel) ? $channel : null;
    }

    /**
     * Le `ts` du message concerné. Pour une modification ou une suppression, il
     * est dans le sous-objet et non à la racine : c'est le message d'origine
     * qu'on veut désigner, pas l'événement qui le modifie.
     */
    private static function extractTs(array $payload): ?string
    {
        $event = $payload['event'] ?? [];
        foreach ([$event['message']['ts'] ?? null, $event['deleted_ts'] ?? null, $event['ts'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
