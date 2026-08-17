<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;

/**
 * Removes stale "remember me" session rows.
 *
 * Each row is a long-lived login token. Craft's own collector uses `rememberedUserSessionDuration`
 * — this uses its own window, which is useful when that setting is generous for convenience but
 * the security review wants old tokens gone sooner. Deleting a row logs that browser out; it does
 * not delete the user.
 */
class SessionsSweeper extends AgedRowsSweeper
{
    public static function handle(): string
    {
        return 'sessions';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Stale login sessions');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes “remember me” session tokens that haven’t been used within the window. Affected users simply sign in again.');
    }

    protected function tables(): array
    {
        return [Table::SESSIONS => 'dateUpdated'];
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 90];
    }
}
