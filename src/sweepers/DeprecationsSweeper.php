<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;

/**
 * Clears Craft's deprecation log.
 *
 * The log is a development aid that nothing prunes. On a site that has been upgraded a few times
 * it holds warnings about code that was fixed years ago, and every stale entry makes the ones
 * that matter harder to see.
 */
class DeprecationsSweeper extends AgedRowsSweeper
{
    public static function handle(): string
    {
        return 'deprecations';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Deprecation warnings');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Clears entries from Craft’s deprecation log that haven’t recurred within the window. Anything still happening is logged again immediately.');
    }

    protected function tables(): array
    {
        return [Table::DEPRECATIONERRORS => 'lastOccurrence'];
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 30];
    }
}
