<?php

namespace humhub\modules\slackBridge\controllers;

use humhub\modules\admin\components\Controller;
use humhub\modules\slackBridge\models\SlackChannel;
use humhub\modules\slackBridge\models\SlackEvent;
use humhub\modules\slackBridge\Module;
use humhub\modules\slackBridge\services\MessageImporter;
use humhub\modules\slackBridge\services\SlackApi;
use humhub\modules\slackBridge\services\Sweeper;
use humhub\modules\space\models\Space;
use Throwable;
use Yii;
use yii\web\NotFoundHttpException;

/**
 * La page d'administration de la passerelle.
 *
 * Tout ce qui décide du sort d'un message — quel canal, quelle destination,
 * quel topic, quelle condition — se règle ici. Rien de tout cela n'est écrit
 * dans le code : ajouter ou changer une règle ne demande pas de déploiement.
 *
 * Hérite du contrôleur admin de HumHub, qui impose déjà d'être administrateur —
 * la page manipule un jeton bot et un secret de signature.
 */
class AdminController extends Controller
{
    public function actionIndex()
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('slack-bridge');

        return $this->render('index', [
            'module' => $module,
            'rules' => SlackChannel::find()->orderBy(['channel_name' => SORT_ASC])->all(),
            'spaceNames' => $this->spaceNames(),
            'events' => SlackEvent::find()->orderBy(['received_at' => SORT_DESC])->limit(40)->all(),
            'skipped' => $this->skipCounts(),
        ]);
    }

    /**
     * Formulaire d'une règle — création si `$id` est absent, modification sinon.
     *
     * Une seule vue pour les deux : les champs sont identiques, et deux
     * formulaires jumeaux divergeraient au premier ajout de champ.
     */
    public function actionRule(?int $id = null)
    {
        $rule = $id === null ? new SlackChannel() : SlackChannel::findOne(['id' => $id]);
        if ($rule === null) {
            throw new NotFoundHttpException(Yii::t('SlackBridgeModule.base', 'No such rule.'));
        }

        if (Yii::$app->request->isPost) {
            $rule->load(Yii::$app->request->post(), 'SlackChannel');

            // Confort : si l'identifiant vient de la liste, on connaît son nom,
            // autant l'inscrire pour que le tableau reste lisible.
            if ($rule->channel_name === null || $rule->channel_name === '') {
                $rule->channel_name = $this->lookupChannelName((string) $rule->channel_id);
            }

            if ($rule->save()) {
                Yii::$app->session->setFlash('success', Yii::t('SlackBridgeModule.base', 'Rule saved.'));
                return $this->redirect(['index']);
            }
        }

        // Les canaux visibles côté Slack, pour éviter la saisie à la main d'un
        // identifiant. Silencieux en cas d'échec : le formulaire doit rester
        // utilisable sans réseau, ou avant que le jeton ne soit posé.
        $slackChannels = [];
        $slackError = null;
        /** @var Module $module */
        $module = Yii::$app->getModule('slack-bridge');
        if ($module->getBotToken() !== '') {
            try {
                $slackChannels = (new SlackApi())->listChannels();
            } catch (Throwable $e) {
                $slackError = $e->getMessage();
            }
        }

        return $this->render('rule', [
            'rule' => $rule,
            'spaces' => Space::find()->orderBy(['name' => SORT_ASC])->all(),
            'slackChannels' => $slackChannels,
            'slackError' => $slackError,
        ]);
    }

    public function actionSaveSettings()
    {
        $this->forcePostRequest();

        /** @var Module $module */
        $module = Yii::$app->getModule('slack-bridge');
        $post = Yii::$app->request->post();

        // Champ laissé vide = « ne change rien ». La page réaffiche les secrets
        // masqués ; sans cette règle, tout enregistrement les effacerait.
        foreach (['botToken', 'signingSecret'] as $key) {
            $value = trim((string) ($post[$key] ?? ''));
            if ($value !== '') {
                $module->settings->set($key, $value);
            }
        }

        $module->settings->set('attachFiles', empty($post['attachFiles']) ? 0 : 1);

        // Les profils Slack sont mis en cache ; un changement de jeton doit
        // repartir sur des bases propres.
        Yii::$app->cache->flush();

        $this->view->saved();

        return $this->redirect(['index']);
    }

    public function actionToggleChannel(int $id)
    {
        $this->forcePostRequest();

        $rule = SlackChannel::findOne(['id' => $id]);
        if ($rule !== null) {
            $rule->enabled = !$rule->enabled;
            $rule->save(false);
        }

        return $this->redirect(['index']);
    }

    public function actionDeleteChannel(int $id)
    {
        $this->forcePostRequest();

        $rule = SlackChannel::findOne(['id' => $id]);
        if ($rule !== null) {
            // On ne touche pas aux posts déjà publiés : ils appartiennent
            // maintenant au fil où ils ont paru.
            $rule->delete();
            Yii::$app->session->setFlash('success', Yii::t('SlackBridgeModule.base', 'Rule deleted. Messages already mirrored stay where they are.'));
        }

        return $this->redirect(['index']);
    }

    /** Reprise manuelle, pour n'avoir pas à attendre le balayage horaire. */
    public function actionRetry()
    {
        $this->forcePostRequest();

        $counts = Sweeper::run(200, 0);
        Yii::$app->session->setFlash('success', Yii::t(
            'SlackBridgeModule.base',
            '{done} event(s) retried: {posted} mirrored, {skipped} skipped, {failed} failed.',
            [
                'done' => $counts['traites'],
                'posted' => $counts['publies'],
                'skipped' => $counts['ecartes'],
                'failed' => $counts['echecs'],
            ],
        ));

        return $this->redirect(['index']);
    }

    /** @return array<int, string> */
    private function spaceNames(): array
    {
        $names = [];
        foreach (Space::find()->orderBy(['name' => SORT_ASC])->all() as $space) {
            $names[(int) $space->id] = $space->name;
        }

        return $names;
    }

    /**
     * Le décompte des messages écartés, par motif.
     *
     * C'est le chiffre qui rend visible un choix autrement muet : un auteur sans
     * compte HumHub voit son message disparaître sans que le fil ne le signale.
     * Au moins l'admin peut le voir ici.
     *
     * @return array<string, int>
     */
    private function skipCounts(): array
    {
        $rows = SlackEvent::find()
            ->select(['reason', 'total' => 'COUNT(*)'])
            ->where(['status' => SlackEvent::STATUS_SKIPPED])
            ->groupBy('reason')
            ->asArray()
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['reason']] = (int) $row['total'];
        }
        arsort($counts);

        return $counts;
    }

    private function lookupChannelName(string $channelId): ?string
    {
        try {
            foreach ((new SlackApi())->listChannels() as $channel) {
                if ($channel['id'] === strtoupper($channelId)) {
                    return $channel['name'];
                }
            }
        } catch (Throwable $e) {
            // Sans importance : le nom n'est que du confort d'affichage.
        }

        return null;
    }

    /** Étiquettes lisibles des motifs d'écart, pour les vues. */
    public static function skipLabel(?string $reason): string
    {
        return match ($reason) {
            MessageImporter::SKIP_AUTHOR_NOT_MATCHED => Yii::t('SlackBridgeModule.base', "Author has no account here"),
            MessageImporter::SKIP_CHANNEL_NOT_MAPPED => Yii::t('SlackBridgeModule.base', "Channel has no rule"),
            MessageImporter::SKIP_SPACE_MISSING => Yii::t('SlackBridgeModule.base', "Space was deleted"),
            MessageImporter::SKIP_NO_IMAGE => Yii::t('SlackBridgeModule.base', "No image (rule is “images only”)"),
            MessageImporter::SKIP_PARENT_NOT_MIRRORED => Yii::t('SlackBridgeModule.base', "Reply to a message that was never mirrored"),
            MessageImporter::SKIP_COMMENTS_CLOSED => Yii::t('SlackBridgeModule.base', "Comments are closed on that post"),
            // Plus produit depuis que les réponses de fil deviennent des
            // commentaires ; le registre en garde des centaines.
            MessageImporter::SKIP_THREAD_REPLY => Yii::t('SlackBridgeModule.base', "Thread reply (before threads were mirrored)"),
            MessageImporter::SKIP_BOT => Yii::t('SlackBridgeModule.base', "Bot message"),
            MessageImporter::SKIP_NO_AUTHOR => Yii::t('SlackBridgeModule.base', "No author"),
            MessageImporter::SKIP_EMPTY => Yii::t('SlackBridgeModule.base', "Empty message"),
            MessageImporter::SKIP_SUBTYPE => Yii::t('SlackBridgeModule.base', "Message type not mirrored"),
            MessageImporter::SKIP_NOT_A_MESSAGE => Yii::t('SlackBridgeModule.base', "Not a message"),
            MessageImporter::SKIP_UNKNOWN_MESSAGE => Yii::t('SlackBridgeModule.base', "Message was never mirrored"),
            default => (string) $reason,
        };
    }
}
