<?php

use humhub\modules\slackBridge\controllers\AdminController;
use yii\helpers\Html;
use yii\helpers\Url;

/** @var array<int, array{slack_user_id: string, messages: int, name: string, email: string, decision: int|false|null}> $roster */
/** @var humhub\modules\user\models\User[] $users */

$aFaire = count(array_filter($roster, fn(array $line): bool => $line['decision'] === null));
?>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('SlackBridgeModule.base', '<strong>Authors</strong> to pair') ?>
        <a class="btn btn-default btn-sm pull-right" href="<?= Url::to(['index']) ?>"><?= Yii::t('SlackBridgeModule.base', 'Back') ?></a>
    </div>
    <div class="panel-body">
        <p class="text-muted">
            <?= Yii::t('SlackBridgeModule.base', 'A message is mirrored under the account whose email matches its Slack author. These addresses do not match any account here, so their messages are dropped — and the stream reads as though the conversation were complete. Pairing someone here fixes that without changing either address, and brings their skipped messages across.') ?>
        </p>

        <?php if ($roster === []) : ?>
            <p class="text-muted"><?= Yii::t('SlackBridgeModule.base', 'Nobody: every author who wrote here has an account.') ?></p>
        <?php else : ?>
            <form method="post" action="<?= Url::to(['authors']) ?>">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <table class="table">
                    <thead>
                    <tr>
                        <th><?= Yii::t('SlackBridgeModule.base', 'In Slack') ?></th>
                        <th><?= Yii::t('SlackBridgeModule.base', 'Skipped') ?></th>
                        <th><?= Yii::t('SlackBridgeModule.base', 'Account here') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($roster as $line) : ?>
                        <?php $id = Html::encode($line['slack_user_id']); ?>
                        <tr>
                            <td>
                                <strong><?= Html::encode($line['name'] !== '' ? $line['name'] : $line['slack_user_id']) ?></strong><br>
                                <span class="text-muted small"><?= Html::encode($line['email']) ?></span>
                                <input type="hidden" name="label[<?= $id ?>]" value="<?= Html::encode($line['name']) ?>">
                            </td>
                            <td><?= (int) $line['messages'] ?></td>
                            <td>
                                <select class="form-control" name="pair[<?= $id ?>]">
                                    <option value="<?= AdminController::PAIR_UNDECIDED ?>"><?= Yii::t('SlackBridgeModule.base', '— not decided —') ?></option>
                                    <option value="<?= AdminController::PAIR_NEVER ?>" <?= $line['decision'] === false ? 'selected' : '' ?>>
                                        <?= Yii::t('SlackBridgeModule.base', 'Never pair (role account)') ?>
                                    </option>
                                    <?php foreach ($users as $user) : ?>
                                        <option value="<?= (int) $user->id ?>" <?= $line['decision'] === (int) $user->id ? 'selected' : '' ?>>
                                            <?= Html::encode($user->displayName . ' — ' . $user->email) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <button type="submit" class="btn btn-primary"><?= Yii::t('SlackBridgeModule.base', 'Save') ?></button>
                <span class="text-muted small">
                    <?= Yii::t('SlackBridgeModule.base', '{n,plural,=0{Everyone is decided.}=1{# author still undecided.}other{# authors still undecided.}}', ['n' => $aFaire]) ?>
                </span>
            </form>
        <?php endif; ?>
    </div>
</div>
