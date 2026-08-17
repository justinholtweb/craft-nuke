<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\models\SweepResult;

/**
 * Prunes old database backups.
 *
 * The directory nothing cleans up. Every `db/backup`, every pre-update backup Craft takes, and
 * every strike Nuke ran with a backup all land here, each one the size of the database. It is
 * the most common single cause of a site filling its disk.
 *
 * Two conditions, and a file has to satisfy both: it must be older than the window, *and* it must
 * not be one of the newest few. That way "delete backups older than 30 days" on a site that
 * hasn't been backed up in a year doesn't delete the only backup there is.
 */
class BackupsSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'backups';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Old database backups');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes files in storage/backups that are both older than the window and outside the most recent few. The newest backups are always kept, however old they are.');
    }

    public function group(): string
    {
        return self::GROUP_FILES;
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'olderThanDays' => 30,
            'keepNewest' => 5,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'olderThanDays',
                'label' => Craft::t('nuke', 'Older than'),
                'type' => 'days',
                'min' => 1,
                'max' => 3650,
            ],
            [
                'name' => 'keepNewest',
                'label' => Craft::t('nuke', 'Always keep the newest'),
                'type' => 'number',
                'instructions' => Craft::t('nuke', 'Never deleted, whatever their age. Set this to at least 1.'),
                'min' => 1,
                'max' => 1000,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $dir = Craft::$app->getPath()->getDbBackupPath(false);

        if (!is_dir($dir)) {
            $result->skip(Craft::t('nuke', 'No backup directory yet.'));
            return;
        }

        $files = $this->agedFiles(
            $dir,
            $this->cutoff($this->days($config)),
            max(1, (int)($config['keepNewest'] ?? 5)),
            '/\.(sql|zip|gz|bz2)$/i',
        );

        $this->sweepFiles($result, $files, $dryRun);

        if ($result->found > 0) {
            $result->message = Craft::t('nuke', '{size} of backups.', [
                'size' => Bytes::format($result->bytes),
            ]);
        }
    }
}
