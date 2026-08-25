<?php

namespace humhub\modules\slackBridge\models;

use humhub\components\ActiveRecord;
use Throwable;
use Yii;

/**
 * Le lien entre un message Slack et ce qu'il a produit ici.
 *
 * C'est ce qui rend le miroir réversible : une modification ou une suppression
 * dans Slack retrouve par là ce qu'il faut corriger ou retirer.
 *
 * ── UNE LIGNE DÉSIGNE UN POST OU UN COMMENTAIRE ─────────────────────────────
 * `post_id` est toujours renseigné : c'est le post d'accueil. `comment_id` ne
 * l'est que pour une réponse de fil, devenue commentaire sous ce post. La
 * lecture est donc :
 *
 *   comment_id NULL      → message de premier niveau, le post lui appartient
 *   comment_id renseigné → réponse de fil ; le post est celui du fil
 *
 * Garder le post sur les deux formes n'est pas de la redondance : quand un post
 * est retiré, HumHub emporte ses commentaires, et c'est par `post_id` qu'on
 * retrouve les liens devenus creux.
 *
 * @property string $channel_id
 * @property string $message_ts
 * @property int $post_id
 * @property int|null $comment_id
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
    /**
     * @param int|null $spaceId   nul pour un post de profil, qui n'a pas de Space.
     * @param int|null $commentId l'identifiant du commentaire pour une réponse de fil, nul sinon.
     */
    public static function record(string $channelId, string $ts, int $postId, ?int $spaceId, int $authorId, ?int $commentId = null): void
    {
        try {
            Yii::$app->db->createCommand()->upsert(self::tableName(), [
                'channel_id' => $channelId,
                'message_ts' => $ts,
                'post_id' => $postId,
                'comment_id' => $commentId,
                'space_id' => $spaceId,
                'author_id' => $authorId,
                'created_at' => date('Y-m-d H:i:s'),
            ], [
                'post_id' => $postId,
                // Réécrit même à NULL, à dessein : une ligne réutilisée pour un
                // post ne doit pas garder l'identifiant du commentaire d'avant.
                'comment_id' => $commentId,
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

    /** Cette ligne désigne-t-elle un commentaire plutôt qu'un post ? */
    public function getIsComment(): bool
    {
        return $this->comment_id !== null;
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

    /**
     * Oublie tout ce qui pendait à un post — lui-même et les réponses de son
     * fil, devenues commentaires.
     *
     * Appelée quand une suppression Slack retire le post : HumHub emporte les
     * commentaires avec lui, et des liens qui désignent des commentaires
     * disparus feraient répondre « déjà republié » à une reprise d'historique
     * qui, elle, n'a plus rien à quoi se raccrocher.
     */
    public static function forgetByPost(int $postId): void
    {
        try {
            Yii::$app->db->createCommand()
                ->delete(self::tableName(), ['post_id' => $postId])
                ->execute();
        } catch (Throwable $e) {
            Yii::error('slack-bridge : liens du post ' . $postId . ' non retirés — ' . $e->getMessage(), 'slack-bridge');
        }
    }
}
