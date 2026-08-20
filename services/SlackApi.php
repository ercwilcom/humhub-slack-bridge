<?php

namespace humhub\modules\slackBridge\services;

use humhub\libs\HttpClient;
use humhub\modules\slackBridge\Module;
use RuntimeException;
use Throwable;
use Yii;

/**
 * Le strict nécessaire de l'API Slack : à qui appartient un identifiant
 * d'utilisateur, comment s'appelle un canal, et comment récupérer un fichier.
 *
 * On passe par `humhub\libs\HttpClient` et non par cURL directement : c'est lui
 * qui applique la configuration proxy de l'install (CURLHelper).
 */
class SlackApi
{
    private const BASE_URL = 'https://slack.com/api/';

    /**
     * Les identités Slack bougent rarement, et `users.info` est limité à ~100
     * appels/minute. Sans ce cache, un canal actif épuiserait le quota et les
     * messages seraient écartés faute d'avoir pu identifier leur auteur.
     */
    private const EMAIL_CACHE_TTL = 3600;

    /** Un identifiant inconnu le reste rarement longtemps (invitation en cours…). */
    private const EMAIL_MISS_TTL = 300;

    private string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? self::module()->getBotToken();
    }

    private static function module(): Module
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('slack-bridge');

        return $module;
    }

    /**
     * Courriel et nom affichable d'un identifiant Slack, en un seul appel mis
     * en cache. Un message qui mentionne trois personnes déclencherait sinon
     * trois `users.info` de plus que nécessaire.
     *
     * @return array{email: string, name: string} chaînes vides si inconnu.
     */
    public function getUserProfile(string $slackUserId): array
    {
        $cacheKey = 'slack-bridge:user:' . $slackUserId;
        $cached = Yii::$app->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->get('users.info', ['user' => $slackUserId]);
        } catch (Throwable $e) {
            Yii::warning('slack-bridge : users.info a échoué pour ' . $slackUserId . ' — ' . $e->getMessage(), 'slack-bridge');
            // Rien en cache : l'échec est probablement réseau, et le rattrapage
            // horaire doit avoir une vraie deuxième chance.
            return ['email' => '', 'name' => ''];
        }

        $slackProfile = $data['user']['profile'] ?? [];
        $name = '';
        foreach (['display_name', 'real_name'] as $key) {
            if (!empty($slackProfile[$key])) {
                $name = (string) $slackProfile[$key];
                break;
            }
        }
        if ($name === '' && !empty($data['user']['name'])) {
            $name = (string) $data['user']['name'];
        }

        $profile = [
            'email' => trim((string) ($slackProfile['email'] ?? '')),
            'name' => $name,
        ];

        // TTL court quand le courriel manque : c'est souvent transitoire
        // (invitation en cours, scope pas encore accordé).
        Yii::$app->cache->set($cacheKey, $profile, $profile['email'] === '' ? self::EMAIL_MISS_TTL : self::EMAIL_CACHE_TTL);

        return $profile;
    }

    /**
     * Le courriel derrière un identifiant Slack, ou null.
     *
     * null n'est pas une erreur : Slack ne renvoie le courriel que si l'app a le
     * scope `users:read.email`, et une intégration (webhook entrant, app tierce)
     * n'en a pas du tout. Dans les deux cas le message sera écarté, ce qui est
     * le comportement voulu.
     */
    public function getUserEmail(string $slackUserId): ?string
    {
        $email = $this->getUserProfile($slackUserId)['email'];

        return $email === '' ? null : $email;
    }

    /** Le nom affichable d'un utilisateur Slack, pour les mentions et l'admin. */
    public function getUserName(string $slackUserId): ?string
    {
        $name = $this->getUserProfile($slackUserId)['name'];

        return $name === '' ? null : $name;
    }

    /**
     * Les canaux où le bot a été invité — c'est la liste utile pour la page
     * d'admin, puisque Slack n'enverra d'événements que pour ceux-là.
     *
     * @return array<int, array{id: string, name: string, is_private: bool}>
     */
    public function listChannels(int $limit = 200): array
    {
        $out = [];
        $cursor = '';

        do {
            $params = [
                'types' => 'public_channel,private_channel',
                'exclude_archived' => 'true',
                'limit' => $limit,
            ];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $data = $this->get('conversations.list', $params);
            foreach ($data['channels'] ?? [] as $channel) {
                // `is_member` : sans invitation, aucun événement n'arrivera.
                // Proposer un canal non rejoint ne ferait qu'une cartographie muette.
                if (empty($channel['is_member'])) {
                    continue;
                }
                $out[] = [
                    'id' => (string) ($channel['id'] ?? ''),
                    'name' => (string) ($channel['name'] ?? ''),
                    'is_private' => (bool) ($channel['is_private'] ?? false),
                ];
            }
            $cursor = (string) ($data['response_metadata']['next_cursor'] ?? '');
        } while ($cursor !== '');

        return $out;
    }

    /**
     * Rapatrie le contenu d'un fichier Slack.
     *
     * Les URL `url_private*` ne sont pas publiques : sans l'en-tête Bearer,
     * Slack répond une page HTML de connexion avec un code 200. D'où le
     * contrôle de type ci-dessous — sans lui, on attacherait au post une page
     * HTML nommée « photo.jpg ».
     */
    public function downloadFile(string $url, int $maxBytes): string
    {
        $response = (new HttpClient())->createRequest()
            ->setMethod('GET')
            ->setUrl($url)
            ->addHeaders(['Authorization' => 'Bearer ' . $this->token])
            ->setOptions(['timeout' => 30])
            ->send();

        if (!$response->isOk) {
            throw new RuntimeException('Téléchargement refusé par Slack (HTTP ' . $response->statusCode . ').');
        }

        $contentType = (string) $response->headers->get('content-type', '');
        if (stripos($contentType, 'text/html') === 0) {
            throw new RuntimeException('Slack a renvoyé une page HTML — jeton sans accès au fichier.');
        }

        $content = $response->getContent();
        if (strlen($content) > $maxBytes) {
            throw new RuntimeException('Fichier trop volumineux (' . strlen($content) . ' octets).');
        }

        return $content;
    }

    /**
     * Une page d'historique d'un canal, la plus récente d'abord.
     *
     * ⚠️ **Le forfait gratuit borne ce que Slack accepte de rendre à 90 jours**
     * — mesuré le 2026-08-20 sur l'espace de la coop : le plus vieux message
     * accessible des trois canaux branchés datait du jour même moins quatre-
     * vingt-dix. Aucun `oldest` ne remonte au-delà, et le mur AVANCE chaque
     * jour. Une pagination qui s'arrête tôt n'est donc pas forcément un bogue.
     *
     * @param string|null $oldest horodatage Slack à partir duquel remonter
     * @return array{messages: array<int, array>, cursor: string}
     */
    public function history(string $channelId, ?string $oldest = null, string $cursor = '', int $limit = 200): array
    {
        $params = ['channel' => $channelId, 'limit' => $limit];
        if ($oldest !== null && $oldest !== '') {
            $params['oldest'] = $oldest;
        }
        if ($cursor !== '') {
            $params['cursor'] = $cursor;
        }

        $data = $this->get('conversations.history', $params, true);

        return [
            'messages' => $data['messages'] ?? [],
            'cursor' => (string) ($data['response_metadata']['next_cursor'] ?? ''),
        ];
    }

    /**
     * Slack répond 200 même sur erreur applicative : le vrai verdict est dans
     * le champ `ok` du corps. Un appel qui ne vérifierait que le code HTTP
     * lirait des tableaux vides sans jamais voir le problème.
     *
     * ── `$patient`, ET POURQUOI CE N'EST PAS LE DÉFAUT ──────────────────────
     * Slack rend 429 avec un `Retry-After` quand on tire trop vite. Attendre et
     * recommencer est la bonne réponse pour une reprise d'historique, qui a des
     * centaines d'appels à passer et tout le temps du monde.
     *
     * C'est la MAUVAISE réponse partout ailleurs : le chemin du webhook doit
     * rendre la main à Slack en trois secondes, et une seconde d'attente y est
     * une seconde de moins pour publier. Là-bas, un 429 doit remonter tout de
     * suite — l'événement reste au registre et le balayage horaire le reprendra,
     * ce qui est exactement le comportement voulu.
     */
    private function get(string $method, array $params, bool $patient = false): array
    {
        if ($this->token === '') {
            throw new RuntimeException('Jeton bot Slack absent.');
        }

        $response = (new HttpClient())->createRequest()
            ->setMethod('GET')
            ->setUrl(self::BASE_URL . $method)
            ->setData($params)
            ->addHeaders(['Authorization' => 'Bearer ' . $this->token])
            ->setOptions(['timeout' => 15])
            ->send();

        if ($patient && $response->statusCode == 429) {
            // `Retry-After` est en secondes. Le plafond évite qu'un en-tête
            // aberrant fasse dormir la commande jusqu'à demain.
            $wait = min(60, max(1, (int) $response->headers->get('retry-after', 5)));
            sleep($wait);

            return $this->get($method, $params, false);
        }

        if (!$response->isOk) {
            throw new RuntimeException($method . ' : HTTP ' . $response->statusCode);
        }

        $data = $response->getData();
        if (!is_array($data)) {
            throw new RuntimeException($method . ' : réponse illisible.');
        }

        if (empty($data['ok'])) {
            throw new RuntimeException($method . ' : ' . ($data['error'] ?? 'erreur inconnue'));
        }

        return $data;
    }
}
