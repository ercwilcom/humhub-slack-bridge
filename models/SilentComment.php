<?php

namespace humhub\modules\slackBridge\models;

use humhub\modules\comment\models\Comment;
use humhub\modules\content\components\ContentAddonActiveRecord;

/**
 * Un commentaire qui n'annonce rien — la forme que prend une réponse de fil
 * reprise dans l'HISTORIQUE.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ────────────────────────────────────────────
 * Un post repris se tait grâce à `silentContentCreation`, un drapeau du cœur.
 * Le commentaire n'en a pas d'équivalent : `Comment::afterSave()` fabrique
 * toujours son activité, notifie tous les suiveurs du post et pousse un
 * événement temps réel. Reprendre trois mois de fils réveillerait donc, en
 * quelques minutes, tout le monde pour des conversations closes depuis
 * longtemps — sans reprise possible.
 *
 * Une réponse d'il y a deux mois qui notifie cent personnes ment sur ce qu'elle
 * est : ce n'est pas en train d'arriver.
 *
 * ── CE QU'ELLE CHANGE, ET RIEN D'AUTRE ──────────────────────────────────────
 * La ligne écrite en base est celle d'un commentaire ordinaire — même table,
 * aucune trace de cette classe : tout ce qui le relira (le fil, le compte de
 * commentaires, la suppression) verra un `Comment`. Ce qui diffère est
 * l'après-sauvegarde :
 *
 *   — pas d'activité, pas de notification, pas de poussée temps réel ;
 *   — pas de suivi automatique : son auteur n'a pas demandé à être averti de la
 *     suite d'un fil vieux de deux mois ;
 *   — le post d'accueil ne remonte PAS en tête du fil de l'espace, ce qui est le
 *     pendant exact de la date d'un post repris.
 *
 * Le cache des commentaires et l'index de recherche, eux, restent tenus à jour :
 * ils décrivent l'état, pas l'actualité.
 */
class SilentComment extends Comment
{
    /** Le post d'accueil ne remonte pas : la reprise ne réordonne aucun fil. */
    protected $updateContentStreamSort = false;

    /** Répondre il y a deux mois n'est pas s'abonner aujourd'hui. */
    protected $automaticContentFollowing = false;

    /**
     * @inheritdoc
     *
     * On saute `Comment::afterSave()` — activité, notifications, temps réel — et
     * on reprend la chaîne UN CRAN PLUS HAUT plutôt que de ne rien appeler : les
     * deux drapeaux ci-dessus rendent ce maillon inerte, mais il porte encore
     * les événements Yii que d'autres modules écoutent. Appeler un ancêtre par
     * son nom depuis une méthode d'instance est le seul moyen de sauter un
     * maillon ; `parent::` désignerait justement celui qu'on écarte.
     */
    public function afterSave($insert, $changedAttributes)
    {
        $this->flushCache();
        $this->updateContentSearch();

        ContentAddonActiveRecord::afterSave($insert, $changedAttributes);
    }
}
