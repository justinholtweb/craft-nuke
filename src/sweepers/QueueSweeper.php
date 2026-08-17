<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;

/**
 * Clears out failed queue jobs.
 *
 * Craft removes a job when it succeeds; a job that fails stays in the table forever so somebody
 * can look at the error. Nobody ever does, and a site with a broken webhook accumulates thousands
 * — each one carrying its serialised payload, which is what makes the table big rather than long.
 *
 * Waiting and reserved jobs are never touched, whatever their age. A job that hasn't run yet is
 * work the site still intends to do.
 */
class QueueSweeper extends AgedRowsSweeper
{
    public static function handle(): string
    {
        return 'queue';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Failed queue jobs');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes jobs that failed longer ago than the window. Jobs still waiting or in progress are left alone regardless.');
    }

    protected function tables(): array
    {
        return [Table::QUEUE => 'dateFailed'];
    }

    protected function condition(string $table): ?array
    {
        return ['fail' => true];
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 30];
    }
}
