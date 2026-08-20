<?php

use humhub\commands\CronController;
use humhub\modules\admin\widgets\AdminMenu;
use humhub\modules\slackBridge\commands\SlackController;
use humhub\modules\slackBridge\Events;
use humhub\modules\slackBridge\Module;

return [
    'id' => 'slack-bridge',
    'class' => Module::class,
    'namespace' => 'humhub\\modules\\slackBridge',
    'urlManagerRules' => [
        // Slack n'appelle qu'une URL, et toujours en POST. Elle est publique par
        // nécessité : c'est Slack qui nous parle, pas un membre connecté. Ce qui
        // tient la porte, c'est la signature HMAC — voir SlackSignature.
        [
            'pattern' => 'slack/events',
            'route' => 'slack-bridge/webhook/events',
            'verb' => 'POST',
        ],
    ],
    'consoleControllerMap' => [
        // php protected/yii slack-bridge/retry     — repasse sur les événements restés en attente
        // php protected/yii slack-bridge/channels  — liste les canaux visibles par le bot
        'slack-bridge' => SlackController::class,
    ],
    'events' => [
        ['class' => AdminMenu::class, 'event' => AdminMenu::EVENT_INIT, 'callback' => [Events::class, 'onAdminMenuInit']],
        // Filet de sécurité seulement : le chemin normal est le traitement en
        // ligne, juste après avoir répondu 200 à Slack (voir WebhookController).
        // Ceci rattrape ce qui serait mort en route.
        ['class' => CronController::class, 'event' => CronController::EVENT_ON_HOURLY_RUN, 'callback' => [Events::class, 'onCronHourlyRun']],
    ],
];
