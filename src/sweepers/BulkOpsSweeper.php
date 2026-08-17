<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;

/**
 * Clears the bulk-operation bookkeeping Craft uses to coalesce events.
 *
 * `bulkopevents` records that a bulk operation happened so deferred handlers can fire once at the
 * end of it. The rows are consumed within the request that wrote them and mean nothing afterwards.
 */
class BulkOpsSweeper extends AgedRowsSweeper
{
    public static function handle(): string
    {
        return 'bulkOps';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Bulk operation records');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Clears the bulkopevents table, which Craft uses to fire deferred handlers once at the end of a bulk operation. The rows have no meaning after the request that wrote them.');
    }

    protected function tables(): array
    {
        return [Table::BULKOPEVENTS => 'timestamp'];
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 7];
    }
}
