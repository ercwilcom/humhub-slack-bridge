<?php

use humhub\components\Migration;

/**
 * Le registre du miroir apprend les fils : un lien peut désormais désigner un
 * COMMENTAIRE et plus seulement un post.
 *
 * Une réponse de fil Slack devient un commentaire sous le post du message qui a
 * ouvert le fil. Sa ligne garde `post_id` — c'est le post d'accueil, donc la
 * porte d'entrée quand ce post disparaît — et ajoute `comment_id`. Les deux
 * ensemble se lisent sans ambiguïté :
 *
 *   comment_id NULL      → la ligne est un post (un message de premier niveau)
 *   comment_id renseigné → la ligne est un commentaire sous ce post
 *
 * ── POURQUOI PAS UNE SECONDE TABLE ──────────────────────────────────────────
 * Parce que la clé est la même — (canal, ts) — et que c'est elle qui fait tout
 * le travail : une modification ou une suppression arrive de Slack avec un `ts`
 * et rien d'autre. Deux tables voudraient dire deux recherches à chaque
 * événement, et un jour une seule des deux consultée.
 */
class m260824_130000_slack_bridge_comment_link extends Migration
{
    public function safeUp()
    {
        $table = $this->db->getSchema()->getTableSchema('slack_bridge_message', true);

        if ($table === null) {
            // Les migrations passent dans l'ordre de leur nom de classe : la
            // table devrait être là. Absente, c'est une install dont on a retiré
            // les tables du module — il n'y a rien à modifier, et lever ici
            // bloquerait toutes les migrations qui suivent.
            echo "    > slack_bridge_message absente, rien à modifier\n";
            return;
        }

        if (isset($table->columns['comment_id'])) {
            echo "    > colonne comment_id déjà présente\n";
            return;
        }

        $this->addColumn('slack_bridge_message', 'comment_id', $this->integer()->null());
        // Retrouver les réponses d'un post quand celui-ci est retiré : sans cet
        // index, la purge des liens orphelins balaierait toute la table.
        $this->createIndex('idx_slack_bridge_message_comment', 'slack_bridge_message', 'comment_id');
    }

    public function safeDown()
    {
        $table = $this->db->getSchema()->getTableSchema('slack_bridge_message', true);
        if ($table === null || !isset($table->columns['comment_id'])) {
            return;
        }

        $this->dropIndex('idx_slack_bridge_message_comment', 'slack_bridge_message');
        $this->dropColumn('slack_bridge_message', 'comment_id');
    }
}
