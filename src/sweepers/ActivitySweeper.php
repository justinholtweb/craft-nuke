<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;

/**
 * Prunes the "who else is editing this?" activity table.
 *
 * Rows are written whenever someone opens an element for editing and are only read to show the
 * live presence indicators in the control panel. Anything older than a few days answers a
 * question nobody is asking.
 */
class ActivitySweeper extends AgedRowsSweeper
{
    public static function handle(): string
    {
        return 'activity';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Element activity');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Prunes the elementactivity table behind the control panel’s “currently editing” indicators. Only recent rows are ever displayed.');
    }

    protected function tables(): array
    {
        return [Table::ELEMENTACTIVITY => 'timestamp'];
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 30];
    }
}
