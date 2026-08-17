<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Table;
use craft\helpers\Db;
use DateTime;
use justinholtweb\nuke\models\SweepResult;

/**
 * Deletes expired tokens — the single-use URLs behind entry previews, share links and password
 * resets.
 *
 * Age is the wrong measure here: a token carries its own expiry date, and one issued a year ago
 * with a ten-year lifetime is still valid. So this sweeps by expiry, not by age, and a token that
 * has not expired is never removed however old it is.
 */
class TokensSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'tokens';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Expired tokens');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes preview, share and password-reset tokens whose expiry date has passed. Unexpired tokens are left alone whatever their age.');
    }

    public function group(): string
    {
        return self::GROUP_DATABASE;
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $this->sweepRows(
            $result,
            Table::TOKENS,
            ['<', 'expiryDate', Db::prepareDateForDb(new DateTime())],
            $dryRun,
        );
    }
}
