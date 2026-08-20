<?php

use humhub\components\Migration;

/**
 * Les trois tables de la passerelle Slack.
 *
 * ── ELLE NE CRÉE QUE CE QUI MANQUE ──────────────────────────────────────────
 * Le garde n'est pas de la prudence décorative. HumHub suit les migrations par
 * nom de classe **nu**, dans une table partagée : une install dont l'historique
 * porte cette migration sous un ANCIEN nom de classe la verra comme neuve et la
 * rejouera. Sans garde, elle créerait par-dessus des tables déjà pleines.
 */
class m260805_140000_slack_bridge_init extends Migration
{
    /** Vrai si la table est déjà là — une install d'avant le renommage. */
    private function has(string $table): bool
    {
        $schema = $this->db->getSchema();
        $schema->refresh();

        return $schema->getTableSchema($table, true) !== null;
    }

    public function safeUp()
    {
        if ($this->has('slack_bridge_channel')) {
            echo "    > tables déjà présentes, rien à créer\n";
            return;
        }

        // Où va quel canal, et à quelle condition. Rien n'est republié tant
        // qu'une ligne active n'existe pas : la cartographie est la liste
        // blanche, et elle porte aussi les règles.
        $this->createTable('slack_bridge_channel', [
            'id' => $this->primaryKey(),
            'channel_id' => $this->string(32)->notNull(),
            'channel_name' => $this->string(128)->null(),
            // 'space' → le fil d'un espace ; 'author_profile' → le profil de
            // l'auteur, sans espace.
            'target' => $this->string(16)->notNull()->defaultValue('space'),
            // Nul quand la cible est le profil de l'auteur : il n'y a pas de
            // Space à désigner.
            'space_id' => $this->integer()->null(),
            // Topic HumHub apposé à chaque post. 100 = la limite de ContentTag.
            'topic_name' => $this->string(100)->null(),
            // Ne republier que les messages porteurs d'une image.
            'require_image' => $this->boolean()->notNull()->defaultValue(false),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->dateTime()->notNull(),
        ]);
        // Un canal ne peut alimenter qu'un Space : sinon un même message
        // arriverait en double et la déduplication (par ts) n'y verrait rien.
        $this->createIndex('idx_slack_bridge_channel_uniq', 'slack_bridge_channel', 'channel_id', true);

        // Le registre des événements reçus. Il porte trois rôles :
        //   — déduplication : Slack rejoue un événement jusqu'à 3 fois s'il
        //     n'obtient pas son 200 assez vite, et `event_id` est stable ;
        //   — file de reprise : ce qui n'a pas abouti reste `pending`/`failed`
        //     et le balayage horaire y repasse ;
        //   — journal : la page d'admin lit ici pour montrer ce qui a été
        //     écarté, en particulier les auteurs non appariés.
        $this->createTable('slack_bridge_event', [
            'event_id' => $this->string(64)->notNull(),
            'channel_id' => $this->string(32)->null(),
            'message_ts' => $this->string(32)->null(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'reason' => $this->string(191)->null(),
            'post_id' => $this->integer()->null(),
            'payload' => $this->text()->notNull(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'received_at' => $this->dateTime()->notNull(),
            'processed_at' => $this->dateTime()->null(),
            'PRIMARY KEY(event_id)',
        ]);
        $this->createIndex('idx_slack_bridge_event_status', 'slack_bridge_event', ['status', 'received_at']);

        // Le lien message Slack ↔ post HumHub. Sans lui, une modification ou une
        // suppression côté Slack n'aurait aucun moyen de retrouver quoi toucher
        // ici, et le miroir serait à sens unique — donc irrattrapable.
        $this->createTable('slack_bridge_message', [
            'channel_id' => $this->string(32)->notNull(),
            'message_ts' => $this->string(32)->notNull(),
            'post_id' => $this->integer()->notNull(),
            // Nul pour un post de profil : il n'appartient à aucun Space.
            'space_id' => $this->integer()->null(),
            'author_id' => $this->integer()->notNull(),
            'created_at' => $this->dateTime()->notNull(),
            'PRIMARY KEY(channel_id, message_ts)',
        ]);
        $this->createIndex('idx_slack_bridge_message_post', 'slack_bridge_message', 'post_id');
    }

    public function safeDown()
    {
        if (!$this->has('slack_bridge_message')) {
            return;
        }
        $this->dropTable('slack_bridge_message');
        $this->dropTable('slack_bridge_event');
        $this->dropTable('slack_bridge_channel');
    }
}
