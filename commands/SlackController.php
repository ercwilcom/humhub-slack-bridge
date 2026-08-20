<?php

namespace humhub\modules\slackBridge\commands;

use humhub\modules\slackBridge\models\SlackChannel;
use humhub\modules\slackBridge\models\SlackEvent;
use humhub\modules\slackBridge\models\SlackMessage;
use humhub\modules\slackBridge\Module;
use humhub\modules\slackBridge\services\Backfiller;
use humhub\modules\slackBridge\services\MessageImporter;
use humhub\modules\slackBridge\services\SlackApi;
use humhub\modules\slackBridge\services\Sweeper;
use humhub\modules\post\models\Post;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use Throwable;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Outils en ligne de commande de la passerelle Slack.
 *
 *   php protected/yii slack-bridge/status    état de la configuration
 *   php protected/yii slack-bridge/channels  canaux où le bot est présent
 *   php protected/yii slack-bridge/retry     reprise des événements en attente
 *   php protected/yii slack-bridge/backfill  reprise de l'historique (90 jours max)
 *   php protected/yii slack-bridge/retag     appose « Via Slack » au déjà republié
 */
class SlackController extends Controller
{
    /** `--dryRun` : compter ce qui serait repris, sans rien écrire. */
    public $dryRun = false;

    /** `--limit=N` : s'arrêter après N reprises. 0 = tout. */
    public $limit = 0;

    /** `--since=1750000000` : horodatage Slack à partir duquel remonter. */
    public $since = '';

    public function options($actionID): array
    {
        // On AJOUTE aux options du cœur au lieu de les remplacer : les rendre
        // seules ferait refuser `--interactive=0` et `--color`, que toute
        // exécution détachée passe — et le refus nommerait l'option, pas nous.
        return array_merge(
            parent::options($actionID),
            $actionID === 'backfill' ? ['dryRun', 'limit', 'since'] : [],
        );
    }

    /** État de la configuration et compte des derniers événements. */
    public function actionStatus(): int
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('slack-bridge');

        $this->stdout("Passerelle Slack\n\n", Console::BOLD);
        $this->stdout('  URL webhook      : ' . $module->getWebhookUrl() . "\n");
        $this->stdout('  Jeton bot        : ' . ($module->getBotToken() !== '' ? 'défini' : 'ABSENT') . "\n");
        $this->stdout('  Secret signature : ' . ($module->getSigningSecret() !== '' ? 'défini' : 'ABSENT') . "\n");
        $this->stdout('  Pièces jointes   : ' . ($module->getAttachFiles() ? 'oui' : 'non') . "\n\n");

        $rules = SlackChannel::find()->all();
        $this->stdout('Règles : ' . count($rules) . "\n");
        foreach ($rules as $rule) {
            $space = $rule->space_id !== null ? Space::findOne(['id' => $rule->space_id]) : null;
            $this->stdout(sprintf(
                "  %-14s %-34s → %s%s\n",
                $rule->channel_id,
                $rule->channel_name !== null ? '#' . $rule->channel_name : '',
                $rule->describe($space?->name),
                $rule->enabled ? '' : '  (en pause)',
            ));
        }

        $this->stdout("\nÉvénements :\n");
        foreach ([SlackEvent::STATUS_POSTED, SlackEvent::STATUS_SKIPPED, SlackEvent::STATUS_PENDING, SlackEvent::STATUS_FAILED] as $status) {
            $this->stdout(sprintf("  %-8s %d\n", $status, SlackEvent::find()->where(['status' => $status])->count()));
        }

        return ExitCode::OK;
    }

    /**
     * Les canaux où le bot a été invité — donc les seuls qui peuvent alimenter
     * un espace. Sert à récupérer les identifiants (C01ABC…) à coller dans la
     * page d'admin.
     */
    public function actionChannels(): int
    {
        try {
            $channels = (new SlackApi())->listChannels();
        } catch (Throwable $e) {
            $this->stderr('Échec : ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        if ($channels === []) {
            $this->stdout("Aucun canal : le bot n'a été invité nulle part (/invite @<bot> dans Slack).\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($channels as $channel) {
            $this->stdout(sprintf(
                "  %-14s #%s%s\n",
                $channel['id'],
                $channel['name'],
                $channel['is_private'] ? ' (privé)' : '',
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Reprend l'historique des canaux branchés — jusqu'où Slack veut bien aller.
     *
     *   php protected/yii slack-bridge/backfill --dryRun
     *   php protected/yii slack-bridge/backfill C07LWHZFGCC --limit=5
     *   php protected/yii slack-bridge/backfill
     *
     * ⚠️ **Slack ne rend rien de plus vieux que 90 jours** sur un forfait
     * gratuit, et ce mur avance chaque jour : ce qui n'est pas repris
     * aujourd'hui ne le sera jamais. Voir `SlackApi::history()`.
     *
     * La commande est REJOUABLE : un message déjà republié est reconnu et
     * sauté. C'est ce qui permet de la relancer après avoir apparié quelqu'un
     * — ses messages, écartés au premier passage, entreront au second.
     *
     * @param string|null $channelId un canal, ou tous ceux qui ont une règle active
     */
    public function actionBackfill(?string $channelId = null): int
    {
        $rules = $channelId !== null
            ? array_filter([SlackChannel::findOne(['channel_id' => strtoupper($channelId)])])
            : SlackChannel::find()->where(['enabled' => true])->all();

        if ($rules === []) {
            $this->stderr("Aucune règle à reprendre.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout("À BLANC — rien ne sera écrit.\n\n", Console::FG_YELLOW);
        }

        $backfiller = new Backfiller();
        $total = ['vus' => 0, 'deja' => 0, 'repris' => 0, 'ecartes' => 0, 'echecs' => 0];

        foreach ($rules as $rule) {
            $this->stdout('#' . ($rule->channel_name ?? $rule->channel_id) . "\n", Console::BOLD);

            try {
                $counts = $backfiller->run(
                    $rule,
                    $this->since !== '' ? $this->since : null,
                    $this->limit,
                    $this->dryRun,
                    // Un point par message : sur trois cents messages et trois
                    // cents mégaoctets de photos, une commande muette pendant
                    // vingt minutes est indistinguable d'une commande bloquée.
                    fn(string $ts, string $issue) => $this->stdout($this->mark($issue)),
                );
            } catch (Throwable $e) {
                $this->stdout("\n");
                $this->stderr('  Échec : ' . $e->getMessage() . "\n", Console::FG_RED);
                continue;
            }

            foreach ($counts as $key => $value) {
                $total[$key] = ($total[$key] ?? 0) + $value;
            }

            $this->stdout(sprintf(
                "\n  %d vus — %s %d, déjà là %d, écartés %d, échecs %d\n\n",
                $counts['vus'],
                $this->dryRun ? 'à tenter' : 'repris',
                $counts['repris'],
                $counts['deja'],
                $counts['ecartes'],
                $counts['echecs'],
            ));
        }

        $this->stdout(sprintf(
            "TOTAL : %d vus, %s %d, déjà là %d, écartés %d, échecs %d\n",
            $total['vus'],
            $this->dryRun ? 'à tenter' : 'repris',
            $total['repris'],
            $total['deja'],
            $total['ecartes'],
            $total['echecs'],
        ), Console::BOLD);

        if (!$this->dryRun && $total['ecartes'] > 0) {
            // L'écart est le mode de défaillance NORMAL de cette passerelle, et
            // le seul qui ne se voie pas dans le fil : le rappeler ici est le
            // seul endroit où quelqu'un le lira au bon moment.
            $this->stdout(
                "\nLes écartés sont surtout des auteurs sans compte Hub apparié.\n"
                . "Voir APPARIEMENT-A-FAIRE.md : apparier puis relancer les rattrape.\n",
                Console::FG_YELLOW,
            );
        }

        return ExitCode::OK;
    }

    /** Un caractère par message traité — de quoi lire l'avancement sans le détailler. */
    private function mark(string $issue): string
    {
        return match ($issue) {
            'repris' => '.',
            'a_tenter' => '.',
            'deja' => '=',
            'ecartes' => '-',
            default => '!',
        };
    }

    /**
     * Appose « Via Slack » à tout ce qui a DÉJÀ été republié.
     *
     *   php protected/yii slack-bridge/retag
     *
     * Sans ce rattrapage, l'étiquette voudrait dire « republié après le
     * 2026-08-20 » au lieu de « vient de Slack » — une étiquette qui ment sur
     * son propre sens est pire que pas d'étiquette du tout.
     *
     * Rejouable : `Topic::attach()` pose un ensemble, il ne l'empile pas, donc
     * repasser deux fois ne fabrique pas de doublon. Le topic de la règle est
     * reposé EN MÊME TEMPS, pour la même raison — le poser seul l'effacerait.
     */
    public function actionRetag(): int
    {
        $importer = new MessageImporter();
        $tagged = $missing = $failed = 0;

        foreach (SlackMessage::find()->all() as $mirror) {
            $post = Post::findOne(['id' => $mirror->post_id]);
            if ($post === null) {
                // Le post a été supprimé depuis (une rétractation côté Slack,
                // ou un ménage d'admin). Le miroir survit à son post ; ce n'est
                // pas une anomalie à corriger ici.
                $missing++;
                continue;
            }

            $rule = SlackChannel::findOne(['channel_id' => $mirror->channel_id]);
            $names = array_filter([$rule?->topic_name, MessageImporter::ORIGIN_TOPIC]);

            // Sous l'identité de l'auteur, comme à la republication : poser une
            // étiquette touche au contenu de quelqu'un, et le cœur regarde qui
            // le fait.
            $author = User::findOne(['id' => $post->content->created_by]);
            $previous = Yii::$app->user->identity;

            try {
                if ($author !== null) {
                    Yii::$app->user->setIdentity($author);
                }
                $importer->applyTopics($post, $names);
                $tagged++;
            } catch (Throwable $e) {
                $this->stderr('  post ' . $post->id . ' : ' . $e->getMessage() . "\n", Console::FG_RED);
                $failed++;
            } finally {
                Yii::$app->user->setIdentity($previous);
            }

            if ($tagged % 25 === 0) {
                $this->stdout('.');
            }
        }

        $this->stdout(sprintf(
            "\n%d posts étiquetés, %d disparus, %d échecs\n",
            $tagged,
            $missing,
            $failed,
        ));

        return ExitCode::OK;
    }

    /** Reprend les événements en attente ou en échec. */
    public function actionRetry(int $limit = 100): int
    {
        // Pas de délai de grâce ici : l'appel est manuel, donc délibéré.
        $counts = Sweeper::run($limit, 0);

        $this->stdout(sprintf(
            "Traités : %d — publiés %d, écartés %d, échecs %d\n",
            $counts['traites'],
            $counts['publies'],
            $counts['ecartes'],
            $counts['echecs'],
        ));

        return ExitCode::OK;
    }
}
