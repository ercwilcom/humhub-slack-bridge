<?php

namespace humhub\modules\slackBridge;

use Yii;

/**
 * Passerelle Slack → Hub.
 *
 * Un message publié dans un canal Slack cartographié reparaît dans le fil du
 * Space correspondant, signé par le membre qui l'a écrit.
 *
 * Deux choses à garder en tête en lisant ce module :
 *
 * 1. **L'auteur doit exister ici.** On apparie sur le courriel Slack ↔ courriel
 *    HumHub. Sans appariement, le message est ignoré — décision explicite
 *    d'Eric. Conséquence à assumer : un canal se reflète *partiellement*, et le
 *    fil de l'espace ne le dit pas. La page d'admin compte ces messages écartés
 *    pour que le trou soit visible quelque part.
 *
 * 2. **Le miroir doit pouvoir se rétracter.** Un canal recopié intégralement
 *    rend public ce qui a été écrit sur un ton de conversation. On propage donc
 *    les modifications et les suppressions Slack : effacer là-bas efface ici.
 *    Sans ça, la seule façon de retirer un mot malheureux serait de passer par
 *    l'admin du Hub.
 */
class Module extends \humhub\components\Module
{
    public $resourcesPath = 'resources';

    /**
     * Au-delà, on ne rapatrie pas la pièce jointe (octets). Sert de garde-fou
     * indépendant du plafond HumHub, qu'on lit aussi au moment du transfert.
     */
    public int $maxAttachmentSize = 25 * 1024 * 1024;

    /** Jeton bot (`xoxb-…`). Sert aux appels API et au rapatriement des fichiers. */
    public function getBotToken(): string
    {
        return (string) $this->settings->get('botToken', '');
    }

    /** Secret de signature de l'app Slack. Sans lui, rien n'est accepté. */
    public function getSigningSecret(): string
    {
        return (string) $this->settings->get('signingSecret', '');
    }

    /** Rapatrier les fichiers et images joints aux messages. */
    public function getAttachFiles(): bool
    {
        return (bool) $this->settings->get('attachFiles', 1);
    }

    /**
     * Tant que les deux secrets ne sont pas posés, la passerelle refuse tout :
     * un endpoint public sans secret de signature accepterait n'importe quoi.
     */
    public function getIsConfigured(): bool
    {
        return $this->getBotToken() !== '' && $this->getSigningSecret() !== '';
    }

    /** URL à coller dans « Event Subscriptions » côté Slack. */
    public function getWebhookUrl(): string
    {
        return rtrim((string) Yii::$app->settings->get('baseUrl'), '/') . '/slack/events';
    }
}
