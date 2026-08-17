<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;

/**
 * Clears the scratch tables behind "Update asset indexes".
 *
 * Every indexing run writes one row per file it walked into `assetindexdata`, which on a volume of
 * 200,000 images means 200,000 rows that exist only for the duration of the run. A session that
 * is cancelled, or that dies part-way, leaves the lot behind.
 */
class AssetIndexingSweeper extends AgedRowsSweeper
{
    public static function handle(): string
    {
        return 'assetIndexing';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Asset indexing leftovers');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Clears finished and abandoned asset-indexing sessions and their per-file scratch rows. Running an index again rebuilds everything it needs.');
    }

    protected function tables(): array
    {
        return [
            Table::ASSETINDEXDATA => 'dateCreated',
            Table::ASSETINDEXINGSESSIONS => 'dateCreated',
        ];
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 7];
    }
}
