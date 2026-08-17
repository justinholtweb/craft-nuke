<?php

namespace justinholtweb\nuke\services;

use Craft;
use craft\base\Component;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\Plugin;
use RuntimeException;
use Throwable;

/**
 * Database backups taken immediately before a strike.
 *
 * Craft can already dump the database; what this adds is naming the dump after the run that
 * caused it, so six weeks later the file called `nuke-run-42-…sql` is obviously the one to
 * restore, and the run record links straight to it.
 */
class Backups extends Component
{
    /**
     * Backs the database up and returns the path.
     *
     * Throws rather than returning null on failure. A caller that asked for a backup asked for it
     * because it is about to delete something; quietly continuing without one turns a safety
     * feature into a false sense of one.
     */
    public function take(?int $runId = null): string
    {
        $db = Craft::$app->getDb();
        $dir = Craft::$app->getPath()->getDbBackupPath();
        $name = sprintf(
            'nuke-%s-%s.sql',
            $runId !== null ? "run-$runId" : 'strike',
            date('Y-m-d-His'),
        );
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        try {
            $db->backupTo($path);
        } catch (Throwable $e) {
            Craft::error("Pre-strike backup failed: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            throw new RuntimeException(Craft::t('nuke', 'The backup failed, so nothing was deleted: {message}', [
                'message' => $e->getMessage(),
            ]), 0, $e);
        }

        if (!is_file($path) || filesize($path) === 0) {
            throw new RuntimeException(Craft::t('nuke', 'The backup produced no file, so nothing was deleted.'));
        }

        Craft::info("Pre-strike backup written to $path (" . Bytes::format(filesize($path)) . ')', Plugin::LOG_CATEGORY);

        return $path;
    }

    /**
     * Every backup file Craft knows about, newest first.
     *
     * @return array<int, array{path: string, name: string, size: int, modified: int}>
     */
    public function all(): array
    {
        $dir = Craft::$app->getPath()->getDbBackupPath(false);

        if (!is_dir($dir)) {
            return [];
        }

        $backups = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            // Craft writes `.sql` and, with `compressBackups`, `.zip`. Anything else in this
            // directory was put there by a human and is not ours to reason about.
            if (!preg_match('/\.(sql|zip|gz|bz2)$/i', $path)) {
                continue;
            }

            $backups[] = [
                'path' => $path,
                'name' => basename($path),
                'size' => (int)filesize($path),
                'modified' => (int)filemtime($path),
            ];
        }

        usort($backups, fn(array $a, array $b) => $b['modified'] <=> $a['modified']);

        return $backups;
    }

    /**
     * Whether a backup file taken by a run is still on disk.
     */
    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && is_file($path);
    }
}
