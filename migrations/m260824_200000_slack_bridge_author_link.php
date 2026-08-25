<?php

use humhub\components\Migration;

/**
 * L'appariement EXPLICITE d'un auteur Slack à un compte du Hub.
 *
 * ── POURQUOI UNE TABLE PLUTÔT QU'UNE ADRESSE À CORRIGER ─────────────────────
 * La passerelle apparie sur le courriel : profil Slack ↔ compte du Hub. Quand
 * les deux adresses diffèrent, la seule réparation possible jusqu'ici était de
 * changer l'une des deux — demander à la personne de modifier son profil Slack,
 * ou éditer son compte ici. Le premier est hors de notre portée, et le second
 * est dangereux : depuis la bascule d'identité, l'adresse d'un compte du Hub le
 * relie à son identité côté plateforme ; la corriger pour réparer une
 * republication désaligne une connexion.
 *
 * Cette table est la troisième voie : une correspondance posée par un admin, qui
 * ne touche à aucune des deux adresses. Elle fait autorité sur le courriel —
 * c'est précisément pour les cas où les deux ne se ressemblent pas qu'elle
 * existe.
 *
 * ── `user_id` NUL VEUT DIRE QUELQUE CHOSE ───────────────────────────────────
 * « À ne jamais apparier », et c'est une décision aussi utile que l'autre : un
 * espace de travail Slack a des comptes de rôle et des comptes d'équipe. Les
 * apparier publierait au nom d'un membre qui n'a rien écrit. Sans cette valeur,
 * ces comptes-là resteraient éternellement dans la liste des choses à faire.
 */
class m260824_200000_slack_bridge_author_link extends Migration
{
    public function safeUp()
    {
        if ($this->db->getSchema()->getTableSchema('slack_bridge_author', true) !== null) {
            echo "    > slack_bridge_author déjà présente\n";
            return;
        }

        $this->createTable('slack_bridge_author', [
            // L'identifiant Slack (U…), et non le courriel : c'est ce qui ne
            // change pas quand la personne modifie son profil.
            'slack_user_id' => $this->string(32)->notNull(),
            // Nul = à ne jamais apparier (compte de rôle, compte d'équipe).
            'user_id' => $this->integer()->null(),
            // Le nom lu dans Slack au moment de l'appariement, pour que la page
            // d'admin reste lisible même quand l'API Slack est injoignable.
            'label' => $this->string(191)->null(),
            'created_at' => $this->dateTime()->notNull(),
            'PRIMARY KEY(slack_user_id)',
        ]);
    }

    public function safeDown()
    {
        if ($this->db->getSchema()->getTableSchema('slack_bridge_author', true) === null) {
            return;
        }
        $this->dropTable('slack_bridge_author');
    }
}
