<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\models\SweepResult;

/**
 * Prunes rotated log files.
 *
 * Craft's Monolog targets rotate daily and keep a fixed number of files, but that only governs the
 * logs Craft itself writes. A site accumulates others: logs from plugins with their own targets,
 * files left by a previous Craft version, and whatever the queue wrote before someone turned the
 * level down. Today's log is never touched.
 */
class LogsSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'logs';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Old log files');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes files in storage/logs older than the window. Files written today are always kept, so nothing that is being appended to right now can be removed underneath it.');
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
                'instructions' => Craft::t('nuke', 'Minimum 1 — a log being written to right now is never a candidate.'),
                'min' => 1,
                'max' => 3650,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $dir = Craft::$app->getPath()->getLogPath(false);

        if (!is_dir($dir)) {
            $result->skip(Craft::t('nuke', 'No log directory.'));
            return;
        }

        // Never less than a day, whatever the setting says. Truncating the file the process is
        // currently appending to is a good way to lose the record of what this sweep just did.
        $files = $this->agedFiles($dir, $this->cutoff(max(1, $this->days($config))));

        $this->sweepFiles($result, $files, $dryRun);

        if ($result->found > 0) {
            $result->message = Craft::t('nuke', '{size} of logs.', [
                'size' => Bytes::format($result->bytes),
            ]);
        }
    }
}
