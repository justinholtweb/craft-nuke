<?php

namespace justinholtweb\nuke\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Creates the run ledger.
 *
 * One table. Everything else Nuke needs is already in Craft's schema — that is rather the point
 * of a plugin that deletes things rather than storing them.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%nuke_runs}}', [
            'id' => $this->primaryKey(),

            // `strike` or `sweep`.
            'type' => $this->string(16)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),

            // A sentence describing what ran, so the index screen needs no joins and no
            // reconstruction of a target whose section may since have been deleted.
            'summary' => $this->text(),
            'note' => $this->text(),

            // The target, the preview and the result, exactly as they were. Stored as JSON
            // rather than normalised: this is an audit record, and an audit record that changes
            // shape when the schema does is not one.
            'target' => $this->json(),
            'preview' => $this->json(),
            'outcome' => $this->json(),

            'backupPath' => $this->string(1000),

            // Denormalised for sorting and for the dashboard, which would otherwise parse JSON
            // on every row of a 100-row list.
            'removed' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),
            'duration' => $this->decimal(10, 3)->notNull()->defaultValue(0),

            'dryRun' => $this->boolean()->notNull()->defaultValue(false),
            'scheduled' => $this->boolean()->notNull()->defaultValue(false),

            'userId' => $this->integer(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%nuke_runs}}', ['type', 'dateCreated'], false);
        $this->createIndex(null, '{{%nuke_runs}}', ['status'], false);

        // `SET NULL`, not `CASCADE`: deleting the person who ran a strike must not delete the
        // record that the strike happened.
        $this->addForeignKey(null, '{{%nuke_runs}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%nuke_runs}}');

        return true;
    }
}
