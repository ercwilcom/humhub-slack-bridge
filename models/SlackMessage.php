<?php

namespace humhub\modules\slackBridge\models;

use humhub\components\ActiveRecord;
use Throwable;
use Yii;

/**
 * Le lien entre un message Slack et le post qu'il a produit ici.
 *
 * C'est ce qui rend le miroir réversible : une modification ou une suppression
 * dans Slack retrouve par là le post à corriger ou à retirer.
 *
 * @property string $channel_id
 * @property string $message_ts
 * @property int $post_id
 * @property int $space_id
 * @property int $author_id
 * @property string $created_at
 */
class SlackMessage extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'slack_bridge_message';
    }

    public static function primaryKey(): array
    {
        return ['channel_id', 'message_ts'];
    }

    /**
     * Best-effort, comme le registre des annonces : un post publié mais non
     * inscrit reste visible et lisible — il devient seulement impossible à
     * modifier ou supprimer depuis Slack. Ça ne doit pas annuler la publication.
     */
    /** @param int|null $spaceId nul pour un post de profil, qui n'a pas de Space. */
    public static function record(string $channelId, string $ts, int $postId, ?int $spaceId, int $authorId): void
    {
        try {
            Yii::$app->db->createCommand()->upsert(self::tableName(), [
                'channel_id' => $channelId,
                'message_ts' => $ts,
                'post_id' => $postId,
                'space_id' => $spaceId,
                'author_id' => $authorId,
                'created_at' => date('Y-m-d H:i:s'),
            ], [
                'post_id' => $postId,
                'space_id' => $spaceId,
                'author_id' => $authorId,
            ])->execute();
        } catch (Throwable $e) {
            Yii::error('slack-bridge : lien message non inscrit (post ' . $postId . ') — ' . $e->getMessage(), 'slack-bridge');
        }
    }

    public static function findByTs(string $channelId, string $ts): ?self
    {
        return self::findOne(['channel_id' => $channelId, 'message_ts' => $ts]);
    }

    public static function forget(string $channelId, string $ts): void
    {
        try {
            Yii::$app->db->createCommand()
                ->delete(self::tableName(), ['channel_id' => $channelId, 'message_ts' => $ts])
                ->execute();
        } catch (Throwable $e) {
            Yii::error('slack-bridge : lien message non retiré — ' . $e->getMessage(), 'slack-bridge');
        }
    }
}
