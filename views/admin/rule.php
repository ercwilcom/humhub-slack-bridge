<?php

use humhub\modules\slackBridge\models\SlackChannel;
use yii\helpers\Html;
use yii\helpers\Url;

/** @var SlackChannel $rule */
/** @var humhub\modules\space\models\Space[] $spaces */
/** @var array $slackChannels */
/** @var string|null $slackError */

$isNew = $rule->isNewRecord;
$errors = $rule->getErrors();
?>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= $isNew
            ? Yii::t('SlackBridgeModule.base', '<strong>New</strong> rule')
            : Yii::t('SlackBridgeModule.base', '<strong>Edit</strong> rule') ?>
    </div>
    <div class="panel-body">

        <?php if ($errors) : ?>
            <div class="alert alert-danger">
                <?php foreach ($rule->getFirstErrors() as $message) : ?>
                    <div><?= Html::encode($message) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">

            <div class="form-group">
                <label><?= Yii::t('SlackBridgeModule.base', 'Slack channel') ?></label>
                <?php if (!empty($slackChannels)) : ?>
                    <select name="SlackChannel[channel_id]" class="form-control">
                        <?php foreach ($slackChannels as $channel) : ?>
                            <option value="<?= Html::encode($channel['id']) ?>"
                                <?= $rule->channel_id === $channel['id'] ? 'selected' : '' ?>>
                                #<?= Html::encode($channel['name']) ?><?= $channel['is_private'] ? ' ' . Yii::t('SlackBridgeModule.base', '(private)') : '' ?>
                                — <?= Html::encode($channel['id']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="text" name="SlackChannel[channel_id]" class="form-control"
                           value="<?= Html::encode((string) $rule->channel_id) ?>" placeholder="C01ABC2DEF">
                    <p class="help-block">
                        <?php if ($slackError !== null) : ?>
                            <?= Yii::t('SlackBridgeModule.base', 'Channel list unavailable ({error}).', ['error' => Html::encode($slackError)]) ?>
                        <?php endif; ?>
                        <?= Yii::t('SlackBridgeModule.base', 'You can read the id in Slack: right-click the channel → <em>Copy link</em>; it is the last part of the URL.') ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label><?= Yii::t('SlackBridgeModule.base', 'Destination') ?></label>
                <?php foreach (SlackChannel::targetOptions() as $value => $label) : ?>
                    <div class="radio">
                        <label>
                            <input type="radio" name="SlackChannel[target]" value="<?= $value ?>"
                                   data-slack-target <?= $rule->target === $value || ($rule->target === null && $value === SlackChannel::TARGET_SPACE) ? 'checked' : '' ?>>
                            <?= Html::encode($label) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-group" data-slack-space-row>
                <label><?= Yii::t('SlackBridgeModule.base', 'Space') ?></label>
                <select name="SlackChannel[space_id]" class="form-control">
                    <option value="">&mdash; <?= Yii::t('SlackBridgeModule.base', 'choose') ?> &mdash;</option>
                    <?php foreach ($spaces as $space) : ?>
                        <option value="<?= (int) $space->id ?>" <?= (int) $rule->space_id === (int) $space->id ? 'selected' : '' ?>>
                            <?= Html::encode($space->name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="help-block"><?= Yii::t('SlackBridgeModule.base', 'Not used when the destination is the author’s profile.') ?></p>
            </div>

            <div class="form-group">
                <label>
                    <?= Yii::t('SlackBridgeModule.base', 'Topic applied') ?>
                    <span class="text-muted"><?= Yii::t('SlackBridgeModule.base', '(optional)') ?></span>
                </label>
                <input type="text" name="SlackChannel[topic_name]" class="form-control" maxlength="100"
                       value="<?= Html::encode((string) $rule->topic_name) ?>">
                <p class="help-block">
                    <?= Yii::t('SlackBridgeModule.base', 'A HumHub topic added to every post, created on the first message if it does not exist. Topics belong to their destination: the same name in two Spaces makes two topics.') ?>
                </p>
            </div>

            <div class="checkbox">
                <label>
                    <input type="checkbox" name="SlackChannel[require_image]" value="1" <?= $rule->require_image ? 'checked' : '' ?>>
                    <?= Yii::t('SlackBridgeModule.base', 'Only mirror messages that contain an image') ?>
                </label>
            </div>

            <div class="checkbox">
                <label>
                    <input type="checkbox" name="SlackChannel[enabled]" value="1" <?= $rule->isNewRecord || $rule->enabled ? 'checked' : '' ?>>
                    <?= Yii::t('SlackBridgeModule.base', 'Rule is active') ?>
                </label>
            </div>

            <hr>
            <button type="submit" class="btn btn-primary"><?= Yii::t('SlackBridgeModule.base', 'Save') ?></button>
            <a class="btn btn-default" href="<?= Url::to(['index']) ?>"><?= Yii::t('SlackBridgeModule.base', 'Cancel') ?></a>
        </form>
    </div>
</div>

<script>
    // The Space picker only means something for a Space destination. Hiding it
    // avoids suggesting it counts for the author-profile case.
    (function () {
        var radios = document.querySelectorAll('[data-slack-target]');
        var row = document.querySelector('[data-slack-space-row]');
        if (!radios.length || !row) return;

        function sync() {
            var selected = document.querySelector('[data-slack-target]:checked');
            row.style.display = (selected && selected.value === 'space') ? '' : 'none';
        }

        radios.forEach(function (radio) { radio.addEventListener('change', sync); });
        sync();
    })();
</script>
