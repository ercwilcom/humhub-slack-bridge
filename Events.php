<?php

namespace humhub\modules\slackBridge;

use humhub\modules\admin\widgets\AdminMenu;
use humhub\modules\slackBridge\services\Sweeper;
use humhub\modules\ui\menu\MenuLink;
use Throwable;
use Yii;

class Events
{
    public static function onAdminMenuInit($event)
    {
        if (Yii::$app->user->isGuest || !Yii::$app->user->isAdmin()) {
            return;
        }

        /** @var AdminMenu $menu */
        $menu = $event->sender;
        $menu->addEntry(new MenuLink([
            'label' => Yii::t('SlackBridgeModule.base', 'Slack Bridge'),
            'url' => Yii::$app->urlManager->createUrl(['/slack-bridge/admin/index']),
            'icon' => 'slack',
            'sortOrder' => 610,
            'isActive' => (Yii::$app->controller && Yii::$app->controller->module && Yii::$app->controller->module->id === 'slack-bridge'),
        ]));
    }

    /**
     * Filet de sécurité, pas chemin nominal.
     *
     * Le traitement normal a lieu dans la requête du webhook, juste après la
     * réponse à Slack. Ce balayage rattrape ce qui n'a pas abouti : processus
     * tué en cours de route, API Slack momentanément injoignable, coupure
     * réseau au téléchargement d'une pièce jointe.
     *
     * Il ne doit jamais faire échouer le cron : les autres tâches horaires de
     * l'install passent après lui.
     */
    public static function onCronHourlyRun($event)
    {
        try {
            Sweeper::run();
        } catch (Throwable $e) {
            Yii::error('slack-bridge : balayage horaire interrompu — ' . $e->getMessage(), 'slack-bridge');
        }
    }
}
