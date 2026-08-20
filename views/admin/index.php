<?php

use humhub\modules\slackBridge\controllers\AdminController;
use humhub\modules\slackBridge\models\SlackEvent;
use yii\helpers\Html;
use yii\helpers\Url;

/** @var humhub\modules\slackBridge\Module $module */
/** @var humhub\modules\slackBridge\models\SlackChannel[] $rules */
/** @var array<int, string> $spaceNames */
/** @var SlackEvent[] $events */
/** @var array<string, int> $skipped */

$statusLabels = [
    SlackEvent::STATUS_POSTED => [Yii::t('SlackBridgeModule.base', 'Mirrored'), 'success'],
    SlackEvent::STATUS_SKIPPED => [Yii::t('SlackBridgeModule.base', 'Skipped'), 'default'],
    SlackEvent::STATUS_PENDING => [Yii::t('SlackBridgeModule.base', 'Pending'), 'warning'],
    SlackEvent::STATUS_FAILED => [Yii::t('SlackBridgeModule.base', 'Failed'), 'danger'],
];

$kept = Yii::t('SlackBridgeModule.base', '•••••• (saved — leave empty to keep)');
$unset = Yii::t('SlackBridgeModule.base', 'Not set');
?>

<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('SlackBridgeModule.base', '<strong>Slack</strong> Bridge') ?></div>
    <div class="panel-body">
        <?php if (!$module->getIsConfigured()) : ?>
            <div class="alert alert-warning">
                <?= Yii::t('SlackBridgeModule.base', 'Until both the bot token <em>and</em> the signing secret are saved, the bridge refuses everything that arrives at its URL.') ?>
            </div>
        <?php endif; ?>

        <p class="text-muted">
            <?= Yii::t('SlackBridgeModule.base', 'Paste this into <em>Event Subscriptions</em> in your Slack app:') ?><br>
            <code><?= Html::encode($module->getWebhookUrl()) ?></code>
        </p>

        <form method="post" action="<?= Url::to(['save-settings']) ?>">
            <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">

            <div class="form-group">
                <label><?= Yii::t('SlackBridgeModule.base', 'Bot token ({format})', ['format' => 'xoxb-…']) ?></label>
                <input type="password" class="form-control" name="botToken" autocomplete="new-password"
                       placeholder="<?= Html::encode($module->getBotToken() !== '' ? $kept : $unset) ?>">
            </div>

            <div class="form-group">
                <label><?= Yii::t('SlackBridgeModule.base', 'Signing secret') ?></label>
                <input type="password" class="form-control" name="signingSecret" autocomplete="new-password"
                       placeholder="<?= Html::encode($module->getSigningSecret() !== '' ? $kept : $unset) ?>">
            </div>

            <div class="checkbox">
                <label>
                    <input type="checkbox" name="attachFiles" value="1" <?= $module->getAttachFiles() ? 'checked' : '' ?>>
                    <?= Yii::t('SlackBridgeModule.base', 'Bring across files and images attached to messages') ?>
                </label>
            </div>

            <button type="submit" class="btn btn-primary"><?= Yii::t('SlackBridgeModule.base', 'Save') ?></button>
        </form>

        <hr>
        <p class="text-muted small">
            <?= Yii::t('SlackBridgeModule.base', 'Scopes required on the Slack side: {scopes}.', [
                'scopes' => '<code>channels:history</code>, <code>groups:history</code>, <code>channels:read</code>, '
                    . '<code>groups:read</code>, <code>users:read</code>, <code>users:read.email</code>, <code>files:read</code>',
            ]) ?><br>
            <?= Yii::t('SlackBridgeModule.base', '<code>users:read.email</code> is not optional: the email address is what ties a Slack author to an account here. Without it, everything is skipped.') ?>
        </p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('SlackBridgeModule.base', '<strong>Mirroring</strong> rules') ?>
        <a class="btn btn-primary btn-sm pull-right" href="<?= Url::to(['rule']) ?>"><?= Yii::t('SlackBridgeModule.base', 'New rule') ?></a>
    </div>
    <div class="panel-body">
        <p class="text-muted">
            <?= Yii::t('SlackBridgeModule.base', 'A channel with no active rule is never mirrored: this list is the allow-list.') ?>
        </p>
        <table class="table">
            <thead>
            <tr>
                <th><?= Yii::t('SlackBridgeModule.base', 'Channel') ?></th>
                <th><?= Yii::t('SlackBridgeModule.base', 'What happens') ?></th>
                <th><?= Yii::t('SlackBridgeModule.base', 'State') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rules)) : ?>
                <tr><td colspan="4" class="text-muted"><?= Yii::t('SlackBridgeModule.base', 'No rules — nothing is being mirrored.') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rules as $rule) : ?>
                <tr>
                    <td>
                        <?php if ($rule->channel_name) : ?>
                            <strong>#<?= Html::encode($rule->channel_name) ?></strong><br>
                        <?php endif; ?>
                        <code class="small"><?= Html::encode($rule->channel_id) ?></code>
                    </td>
                    <td><?= Html::encode($rule->describe($spaceNames[$rule->space_id] ?? null)) ?></td>
                    <td>
                        <?php if ($rule->enabled) : ?>
                            <span class="label label-success"><?= Yii::t('SlackBridgeModule.base', 'active') ?></span>
                        <?php else : ?>
                            <span class="label label-default"><?= Yii::t('SlackBridgeModule.base', 'paused') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn btn-default btn-xs" href="<?= Url::to(['rule', 'id' => $rule->id]) ?>"><?= Yii::t('SlackBridgeModule.base', 'Edit') ?></a>
                        <a class="btn btn-default btn-xs" href="<?= Url::to(['toggle-channel', 'id' => $rule->id]) ?>" data-method="post">
                            <?= $rule->enabled ? Yii::t('SlackBridgeModule.base', 'Pause') : Yii::t('SlackBridgeModule.base', 'Resume') ?>
                        </a>
                        <a class="btn btn-danger btn-xs" href="<?= Url::to(['delete-channel', 'id' => $rule->id]) ?>"
                           data-method="post" data-confirm="<?= Html::encode(Yii::t('SlackBridgeModule.base', 'Delete this rule? Messages already mirrored stay where they are.')) ?>">
                            <?= Yii::t('SlackBridgeModule.base', 'Delete') ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('SlackBridgeModule.base', '<strong>Skipped</strong> messages') ?></div>
    <div class="panel-body">
        <p class="text-muted">
            <?= Yii::t('SlackBridgeModule.base', 'A skipped message is mirrored nowhere, and nothing marks the gap in the stream. That is what happens to authors with no account here: their share of the conversation is missing, and the thread reads as though it were complete. This table is the only place that absence is visible.') ?>
        </p>
        <table class="table">
            <thead><tr>
                <th><?= Yii::t('SlackBridgeModule.base', 'Reason') ?></th>
                <th><?= Yii::t('SlackBridgeModule.base', 'Total') ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($skipped)) : ?>
                <tr><td colspan="2" class="text-muted"><?= Yii::t('SlackBridgeModule.base', 'Nothing skipped so far.') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($skipped as $reason => $total) : ?>
                <tr>
                    <td><?= Html::encode(AdminController::skipLabel($reason)) ?></td>
                    <td><?= (int) $total ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('SlackBridgeModule.base', '<strong>Recent</strong> events') ?>
        <a class="btn btn-default btn-sm pull-right" href="<?= Url::to(['retry']) ?>" data-method="post">
            <?= Yii::t('SlackBridgeModule.base', 'Retry pending events') ?>
        </a>
    </div>
    <div class="panel-body">
        <table class="table table-condensed">
            <thead>
            <tr>
                <th><?= Yii::t('SlackBridgeModule.base', 'Received') ?></th>
                <th><?= Yii::t('SlackBridgeModule.base', 'Channel') ?></th>
                <th><?= Yii::t('SlackBridgeModule.base', 'State') ?></th>
                <th><?= Yii::t('SlackBridgeModule.base', 'Detail') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($events)) : ?>
                <tr><td colspan="4" class="text-muted"><?= Yii::t('SlackBridgeModule.base', 'No events received. Slack has not sent anything to this installation yet.') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($events as $event) : ?>
                <?php [$label, $style] = $statusLabels[$event->status] ?? [$event->status, 'default']; ?>
                <tr>
                    <td class="text-muted small"><?= Html::encode($event->received_at) ?></td>
                    <td><code><?= Html::encode((string) $event->channel_id) ?></code></td>
                    <td><span class="label label-<?= $style ?>"><?= Html::encode($label) ?></span></td>
                    <td class="small">
                        <?php if ($event->status === SlackEvent::STATUS_SKIPPED) : ?>
                            <?= Html::encode(AdminController::skipLabel($event->reason)) ?>
                        <?php elseif ($event->status === SlackEvent::STATUS_FAILED) : ?>
                            <span class="text-danger"><?= Html::encode((string) $event->reason) ?></span>
                            (<?= Yii::t('SlackBridgeModule.base', '{n,plural,=1{# attempt}other{# attempts}}', ['n' => (int) $event->attempts]) ?>)
                        <?php elseif ($event->post_id) : ?>
                            <?= Yii::t('SlackBridgeModule.base', 'post #{id}', ['id' => (int) $event->post_id]) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
