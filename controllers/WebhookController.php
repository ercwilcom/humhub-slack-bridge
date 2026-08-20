<?php

namespace humhub\modules\slackBridge\controllers;

use humhub\modules\slackBridge\models\SlackEvent;
use humhub\modules\slackBridge\Module;
use humhub\modules\slackBridge\services\MessageImporter;
use humhub\modules\slackBridge\services\SlackSignature;
use Throwable;
use Yii;
use yii\helpers\Json;
use yii\web\Controller;
use yii\web\Response;

/**
 * Le seul point d'entrée de Slack.
 *
 * Il hérite de `yii\web\Controller` et non du contrôleur HumHub : la requête
 * vient d'une machine, il n'y a pas de session à exiger ni de jeton CSRF à
 * présenter. Ce qui tient la porte, c'est la signature — vérifiée avant toute
 * autre chose, avant même de regarder ce que la requête raconte.
 *
 * Toute la contrainte de conception tient à une phrase de la doc Slack : si le
 * 200 n'arrive pas en trois secondes, l'événement est rejoué (jusqu'à trois
 * fois), et un endpoint qui échoue trop souvent finit désabonné. Publier un
 * post, résoudre un auteur et rapatrier une pièce jointe ne tiennent pas dans
 * ce budget.
 *
 * D'où la séquence : on inscrit l'événement au registre, on répond, on ferme la
 * connexion, et **ensuite seulement** on travaille. L'inscription précède la
 * réponse à dessein — c'est elle qui rend un rejeu inoffensif et qui laisse une
 * trace reprenable si le processus meurt juste après.
 *
 * On ne passe pas par la file HumHub : le `queue/run` de prod est volontairement
 * espacé (toutes les 24 minutes), ce qui ferait arriver les messages avec une
 * demi-heure de retard.
 */
class WebhookController extends Controller
{
    public $enableCsrfValidation = false;
    public $layout = false;

    public function actionEvents()
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('slack-bridge');

        // Le corps **brut** : le HMAC porte sur les octets reçus. Passer par les
        // paramètres analysés puis ré-encoder ferait échouer toute vérification.
        $body = Yii::$app->request->getRawBody();

        if (!$module->getIsConfigured()) {
            // 503 et non 401 : la requête n'est pas en cause, c'est l'install qui
            // n'est pas prête. Slack réessaiera.
            return $this->status(503, ['error' => 'not_configured']);
        }

        $valid = SlackSignature::isValid(
            $module->getSigningSecret(),
            (string) Yii::$app->request->headers->get('X-Slack-Request-Timestamp', ''),
            (string) Yii::$app->request->headers->get('X-Slack-Signature', ''),
            $body,
        );

        if (!$valid) {
            Yii::warning('slack-bridge : signature refusée (' . Yii::$app->request->userIP . ')', 'slack-bridge');
            return $this->status(401, ['error' => 'bad_signature']);
        }

        try {
            $payload = Json::decode($body, true);
        } catch (Throwable $e) {
            return $this->status(400, ['error' => 'bad_payload']);
        }

        if (!is_array($payload)) {
            return $this->status(400, ['error' => 'bad_payload']);
        }

        // Poignée de main initiale, à la configuration de l'abonnement.
        if (($payload['type'] ?? '') === 'url_verification') {
            return $this->status(200, ['challenge' => (string) ($payload['challenge'] ?? '')]);
        }

        if (($payload['type'] ?? '') !== 'event_callback') {
            return $this->status(200, ['ok' => true, 'ignored' => true]);
        }

        $eventId = (string) ($payload['event_id'] ?? '');
        if ($eventId === '') {
            return $this->status(200, ['ok' => true, 'ignored' => true]);
        }

        $record = SlackEvent::claim($eventId, $payload);
        if ($record === null) {
            // Déjà connu : rejeu de Slack, ou double livraison. On acquiesce
            // sans rien refaire.
            return $this->status(200, ['ok' => true, 'duplicate' => true]);
        }

        $this->acknowledge();

        // À partir d'ici, Slack est parti. Rien de ce qui suit ne peut plus lui
        // renvoyer d'erreur — et process() ne lève pas, il marque le registre.
        (new MessageImporter())->process($record);

        return Yii::$app->end();
    }

    /**
     * Répond 200 et libère la connexion.
     *
     * Rendre la main tout en laissant PHP continuer porte un nom différent selon
     * le serveur : `fastcgi_finish_request()` sous PHP-FPM,
     * `litespeed_finish_request()` sous LiteSpeed — ce que sert couramment un
     * hébergement cPanel comme celui de prod. On essaie les deux.
     *
     * Si aucune n'existe (mod_php), le traitement se fait avant la fermeture de
     * la connexion, au risque de dépasser les trois secondes. Ce n'est pas grave
     * et c'est prévu : le rejeu qui s'ensuivrait tombe sur l'événement déjà
     * inscrit au registre et repart avec un 200 immédiat.
     */
    private function acknowledge(): void
    {
        // Si Slack raccroche, on continue quand même : l'événement est inscrit,
        // l'abandonner en chemin le laisserait en attente jusqu'au balayage.
        ignore_user_abort(true);

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->statusCode = 200;
        $response->data = ['ok' => true];
        $response->send();

        foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $finish) {
            if (function_exists($finish)) {
                $finish();
                return;
            }
        }
    }

    private function status(int $code, array $payload): array
    {
        Yii::$app->response->statusCode = $code;
        Yii::$app->response->format = Response::FORMAT_JSON;

        return $payload;
    }
}
