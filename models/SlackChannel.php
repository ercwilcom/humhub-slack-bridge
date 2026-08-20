<?php

namespace humhub\modules\slackBridge\models;

use Yii;
use humhub\components\ActiveRecord;
use humhub\modules\space\models\Space;
use yii\db\ActiveQuery;

/**
 * Une règle de republication : d'où vient le message, où il va, à quelle
 * condition, et sous quelle étiquette.
 *
 * Les trois règles de départ de la coop, pour situer :
 *
 * | Canal                          | Cible                | Condition | Topic |
 * |--------------------------------|----------------------|-----------|-------|
 * | #agenda-culturel-outremont     | Communications       | —         | Agenda culturel Outremont |
 * | #babillard-de-la-coopérative-mil | profil de l'auteur | —         | — |
 * | #petites-annonces              | Dons                 | image     | — |
 *
 * @property int $id
 * @property string $channel_id
 * @property string|null $channel_name
 * @property string $target
 * @property int|null $space_id
 * @property string|null $topic_name
 * @property bool $require_image
 * @property bool $enabled
 * @property string $created_at
 */
class SlackChannel extends ActiveRecord
{
    /** Le message va dans le fil d'un espace. */
    public const TARGET_SPACE = 'space';

    /**
     * Le message va sur le profil de son auteur, sans passer par un espace.
     * L'auteur étant résolu message par message, la cible n'est pas connue
     * d'avance : elle dépend de qui a écrit.
     */
    public const TARGET_AUTHOR_PROFILE = 'author_profile';

    public static function tableName(): string
    {
        return 'slack_bridge_channel';
    }

    public static function targetOptions(): array
    {
        return [
            self::TARGET_SPACE => Yii::t('SlackBridgeModule.base', 'The stream of a Space'),
            self::TARGET_AUTHOR_PROFILE => Yii::t('SlackBridgeModule.base', "The author's own profile (no Space)"),
        ];
    }

    public function rules(): array
    {
        return [
            [['channel_id'], 'required'],
            // Les identifiants de canaux Slack sont en majuscules et commencent
            // par C (public), G (privé) ou D (message direct). On accepte la
            // forme, pas la sémantique : le bot ne verra de toute façon que ce
            // à quoi il a été invité.
            [['channel_id'], 'match', 'pattern' => '/^[A-Z][A-Z0-9]{2,31}$/', 'message' => Yii::t('SlackBridgeModule.base', 'Not a valid Slack channel id (expected something like C01ABC2DEF).')],
            [['channel_id'], 'unique', 'message' => Yii::t('SlackBridgeModule.base', 'This channel already has a rule.')],
            [['channel_name'], 'string', 'max' => 128],
            [['target'], 'in', 'range' => array_keys(self::targetOptions())],
            [['target'], 'default', 'value' => self::TARGET_SPACE],
            [['space_id'], 'integer'],
            // Un espace n'est exigé que si c'est bien là qu'on publie ; la règle
            // du babillard n'en désigne aucun.
            [['space_id'], 'required', 'when' => fn(self $model): bool => $model->target === self::TARGET_SPACE,
                'whenClient' => false, 'message' => Yii::t('SlackBridgeModule.base', 'Choose the destination Space.')],
            [['space_id'], 'exist', 'targetClass' => Space::class, 'targetAttribute' => 'id',
                'message' => Yii::t('SlackBridgeModule.base', 'That Space does not exist.'), 'skipOnEmpty' => true],
            // 100 = la limite de ContentTag::rules(), qu'on ne peut pas dépasser.
            [['topic_name'], 'string', 'max' => 100],
            [['topic_name'], 'trim'],
            [['require_image', 'enabled'], 'boolean'],
            [['require_image'], 'default', 'value' => false],
            [['enabled'], 'default', 'value' => true],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'channel_id' => Yii::t('SlackBridgeModule.base', 'Slack channel'),
            'channel_name' => Yii::t('SlackBridgeModule.base', 'Channel name'),
            'target' => Yii::t('SlackBridgeModule.base', 'Destination'),
            'space_id' => Yii::t('SlackBridgeModule.base', 'Space'),
            'topic_name' => Yii::t('SlackBridgeModule.base', 'Topic applied'),
            'require_image' => Yii::t('SlackBridgeModule.base', 'Only messages with an image'),
            'enabled' => Yii::t('SlackBridgeModule.base', 'Active'),
        ];
    }

    public function beforeSave($insert): bool
    {
        if ($insert) {
            $this->created_at = date('Y-m-d H:i:s');
        }
        $this->channel_id = strtoupper(trim((string) $this->channel_id));

        // Un espace resté sélectionné alors que la cible est le profil serait un
        // piège : la règle afficherait une destination qu'elle n'utilise pas.
        if ($this->target === self::TARGET_AUTHOR_PROFILE) {
            $this->space_id = null;
        }

        if ($this->topic_name === '') {
            $this->topic_name = null;
        }

        return parent::beforeSave($insert);
    }

    public function getSpace(): ActiveQuery
    {
        return $this->hasOne(Space::class, ['id' => 'space_id']);
    }

    public function getTargetsAuthorProfile(): bool
    {
        return $this->target === self::TARGET_AUTHOR_PROFILE;
    }

    /** Résumé lisible de la règle, pour la page d'admin. */
    public function describe(?string $spaceName): string
    {
        $parts = [
            $this->getTargetsAuthorProfile()
                ? Yii::t('SlackBridgeModule.base', "author's profile")
                : ($spaceName ?? Yii::t('SlackBridgeModule.base', 'Space #{id}', ['id' => $this->space_id])),
        ];

        if ($this->topic_name !== null) {
            $parts[] = Yii::t('SlackBridgeModule.base', 'topic “{name}”', ['name' => $this->topic_name]);
        }
        if ($this->require_image) {
            $parts[] = Yii::t('SlackBridgeModule.base', 'images only');
        }

        return implode(' · ', $parts);
    }
}
