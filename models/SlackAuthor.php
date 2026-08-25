<?php

namespace humhub\modules\slackBridge\models;

use humhub\components\ActiveRecord;
use humhub\modules\user\models\User;
use Throwable;
use Yii;

/**
 * Un auteur Slack apparié à la main — ou déclaré à ne jamais apparier.
 *
 * L'appariement ordinaire se fait sur le courriel, et il suffit pour la grande
 * majorité. Cette table est pour le reste : la personne dont l'adresse Slack
 * n'est pas celle que la coop lui connaît, celle qui écrit depuis deux comptes
 * Slack, et les comptes de rôle qu'il ne faut surtout pas rattacher à quelqu'un.
 *
 * @property string $slack_user_id
 * @property int|null $user_id
 * @property string|null $label
 * @property string $created_at
 */
class SlackAuthor extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'slack_bridge_author';
    }

    public static function primaryKey(): array
    {
        return ['slack_user_id'];
    }

    /**
     * Le compte apparié à cet identifiant Slack, s'il y en a un et s'il est
     * actif.
     *
     * Un compte désactivé rend null comme une absence d'appariement : republier
     * au nom de quelqu'un qui a quitté la coop est le défaut que la règle des
     * comptes actifs évite, et un appariement explicite ne doit pas ouvrir une
     * porte que le courriel referme.
     */
    public static function resolve(string $slackUserId): ?User
    {
        $row = self::findOne(['slack_user_id' => $slackUserId]);
        if ($row === null || $row->user_id === null) {
            return null;
        }

        return User::findOne(['id' => $row->user_id, 'status' => User::STATUS_ENABLED]);
    }

    /** Cet identifiant est-il déclaré « à ne jamais apparier » ? */
    public static function isIgnored(string $slackUserId): bool
    {
        $row = self::findOne(['slack_user_id' => $slackUserId]);

        return $row !== null && $row->user_id === null;
    }

    /**
     * Pose ou remplace un appariement. `$userId` nul = à ne jamais apparier.
     *
     * Un upsert plutôt qu'un save() de modèle : la clé est l'identifiant Slack,
     * et repasser sur une décision déjà prise doit l'écraser sans cérémonie.
     */
    public static function bind(string $slackUserId, ?int $userId, ?string $label = null): void
    {
        Yii::$app->db->createCommand()->upsert(self::tableName(), [
            'slack_user_id' => $slackUserId,
            'user_id' => $userId,
            'label' => $label,
            'created_at' => date('Y-m-d H:i:s'),
        ], [
            'user_id' => $userId,
            'label' => $label,
        ])->execute();
    }

    /** Retire la décision : l'appariement redevient une affaire de courriel. */
    public static function forget(string $slackUserId): void
    {
        try {
            Yii::$app->db->createCommand()
                ->delete(self::tableName(), ['slack_user_id' => $slackUserId])
                ->execute();
        } catch (Throwable $e) {
            Yii::error('slack-bridge : appariement non retiré — ' . $e->getMessage(), 'slack-bridge');
        }
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
