<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;

/**
 * Clears old control panel announcements — the "what's new" notices Craft and plugins post after
 * an update. They are per user, so a site with fifty editors stores fifty copies of each.
 */
class AnnouncementsSweeper extends AgedRowsSweeper
{
    public static function handle(): string
    {
        return 'announcements';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Control panel announcements');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Removes “what’s new” announcements older than the window. Craft reposts anything still relevant after the next update.');
    }

    protected function tables(): array
    {
        return [Table::ANNOUNCEMENTS => 'dateCreated'];
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 90];
    }
}
