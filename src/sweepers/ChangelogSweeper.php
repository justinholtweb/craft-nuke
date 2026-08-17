<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;

/**
 * Prunes the per-field change tracking Craft keeps for drafts.
 *
 * `changedattributes` and `changedfields` record which parts of an element a draft has touched,
 * so merging a draft only overwrites what changed. Once the draft is applied or deleted the rows
 * have no further use, and they are written on every autosave — which makes them the fastest-
 * growing pair of tables on an editorially busy site.
 */
class ChangelogSweeper extends AgedRowsSweeper
{
    public static function handle(): string
    {
        return 'changelog';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Draft change tracking');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Prunes the changedattributes and changedfields tables. Keep the window comfortably longer than your longest-lived draft — these rows are what stops a merge overwriting someone else’s edit.');
    }

    protected function tables(): array
    {
        return [
            Table::CHANGEDATTRIBUTES => 'dateUpdated',
            Table::CHANGEDFIELDS => 'dateUpdated',
        ];
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 180];
    }
}
