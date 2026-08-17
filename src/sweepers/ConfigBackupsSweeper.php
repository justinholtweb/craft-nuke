<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\models\SweepResult;

/**
 * Prunes the copies Craft and Composer keep of things they were about to change.
 *
 * `storage/config-backups` and `config-deltas` are written on every project config change;
 * `storage/composer-backups` holds a copy of composer.json and composer.lock from before every
 * plugin install or update. All three are recovery aids for the change that just happened, not an
 * archive.
 */
class ConfigBackupsSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'configBackups';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Config and Composer backups');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Prunes storage/config-backups, config-deltas and composer-backups. Each is a snapshot taken before one change; they are not a history you can roll back through.');
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
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $paths = Craft::$app->getPath();
        $cutoff = $this->cutoff(max(1, $this->days($config)));

        $directories = [
            $paths->getConfigBackupPath(false),
            $paths->getConfigDeltaPath(false),
            $paths->getComposerBackupsPath(false),
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $this->sweepFiles($result, $this->agedTreeFiles($dir, $cutoff), $dryRun);
        }

        if ($result->found > 0) {
            $result->message = Craft::t('nuke', '{size} of snapshots.', [
                'size' => Bytes::format($result->bytes),
            ]);
        }
    }
}
