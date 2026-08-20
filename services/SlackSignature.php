<?php

namespace humhub\modules\slackBridge\services;

/**
 * La seule chose qui distingue un vrai appel de Slack d'un inconnu qui a deviné
 * l'URL. `/slack/events` est publique et non authentifiée ; tout repose ici.
 *
 * Recette Slack (« Verifying requests from Slack ») : HMAC-SHA256 de
 * `v0:<timestamp>:<corps brut>` avec le secret de signature.
 */
class SlackSignature
{
    /** Au-delà, on refuse : ça borne la fenêtre de rejeu à cinq minutes. */
    public const MAX_SKEW = 300;

    /**
     * @param string $body corps **brut** de la requête. Surtout pas un tableau
     *                     ré-encodé : le moindre écart d'encodage fait échouer
     *                     le HMAC, qui porte sur les octets reçus.
     */
    public static function isValid(string $secret, string $timestamp, string $signature, string $body, ?int $now = null): bool
    {
        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        // Un timestamp non numérique donnerait 0 après cast, donc un écart
        // énorme : refusé plus bas. On le dit quand même explicitement.
        if (!ctype_digit(ltrim($timestamp, '-'))) {
            return false;
        }

        $now ??= time();
        if (abs($now - (int) $timestamp) > self::MAX_SKEW) {
            return false;
        }

        $expected = 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $body, $secret);

        // hash_equals et pas === : comparaison à temps constant, sinon la durée
        // de la réponse laisse deviner la signature octet par octet.
        return hash_equals($expected, $signature);
    }
}
