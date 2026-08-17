<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\models\SweepResult;

/**
 * Clears Craft's scratch directories.
 *
 * Four of them, and all four leak. `runtime/temp` holds whatever a plugin needed a file for;
 * `tempuploads` holds uploads abandoned before the form was submitted; `assetsources` and
 * `imageeditor` hold local copies Craft pulled down from remote volumes to work on. Everything
 * here is regenerated on demand, so the only risk is deleting something a request is using right
 * now — which the age window is there to prevent.
 */
class TempFilesSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'temp';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Temporary files');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Clears runtime/temp, abandoned uploads, and the local copies Craft makes of remote assets while working on them. All of it is regenerated on demand.');
    }

    public function group(): string
    {
        return self::GROUP_FILES;
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'olderThanDays' => 1,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'olderThanDays',
                'label' => Craft::t('nuke', 'Older than'),
                'type' => 'days',
                'instructions' => Craft::t('nuke', 'Keep at least a day so an upload in progress is never swept out from under someone.'),
                'min' => 1,
                'max' => 365,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $paths = Craft::$app->getPath();
        $cutoff = $this->cutoff(max(1, $this->days($config)));

        $directories = [
            $paths->getTempPath(false),
            $paths->getTempAssetUploadsPath(false),
            $paths->getAssetSourcesPath(false),
            $paths->getImageEditorSourcesPath(false),
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $this->sweepFiles($result, $this->agedTreeFiles($dir, $cutoff), $dryRun);
        }

        if (!$dryRun) {
            // Craft already knows how to drop the folder skeleton left behind under tempuploads,
            // and doing it here keeps the directory listing honest rather than full of empties.
            Craft::$app->getGc()->removeEmptyTempFolders();
        }

        if ($result->found > 0) {
            $result->message = Craft::t('nuke', '{size} of scratch files.', [
                'size' => Bytes::format($result->bytes),
            ]);
        }
    }
}
