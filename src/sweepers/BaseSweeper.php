<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use DateTime;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\models\SweepResult;
use justinholtweb\nuke\Plugin;
use Throwable;
use yii\base\BaseObject;

/**
 * Shared plumbing for sweepers: timing, error trapping, the dry-run/execute split, and the
 * handful of operations most of them need — deleting rows older than a date, walking a storage
 * directory, counting bytes.
 *
 * Subclasses implement {@see execute()} once and get both modes from it. The alternative —
 * separate `scan()` and `sweep()` implementations per sweeper — is 20 opportunities for a preview
 * and an execution to drift apart, and every one of them shows up as "it deleted something it
 * didn't warn me about".
 */
abstract class BaseSweeper extends BaseObject implements SweeperInterface
{
    /**
     * Does the work. In dry-run mode, set `$result->found` and leave `$result->removed` alone.
     *
     * @param array<string, mixed> $config
     */
    abstract protected function execute(SweepResult $result, array $config, bool $dryRun): void;

    public function isAvailable(): bool
    {
        return true;
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true];
    }

    public function configFields(): array
    {
        return [];
    }

    public function scan(array $config): SweepResult
    {
        return $this->run($config, true);
    }

    public function sweep(array $config): SweepResult
    {
        return $this->run($config, false);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function run(array $config, bool $dryRun): SweepResult
    {
        $started = microtime(true);
        $config = array_merge($this->defaultConfig(), $config);

        $result = new SweepResult([
            'handle' => static::handle(),
            'label' => $this->label(),
            'group' => $this->group(),
            'dryRun' => $dryRun,
        ]);

        if (!$this->isAvailable()) {
            $result->skip(Craft::t('nuke', 'Not applicable on this site.'));
            $result->duration = microtime(true) - $started;
            return $result;
        }

        try {
            $this->execute($result, $config, $dryRun);
        } catch (Throwable $e) {
            // One broken sweeper must not take the sweep down with it. The others still have
            // work to do, and the report is more useful with 22 results and one failure than
            // with a stack trace and nothing.
            $result->failed = true;
            $result->message = $e->getMessage();
            Craft::error(sprintf('Sweeper “%s” failed: %s', static::handle(), $e->getMessage()), Plugin::LOG_CATEGORY);
        }

        $result->duration = microtime(true) - $started;

        return $result;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Reads a config value as a positive integer, falling back to the sweeper's own default.
     *
     * @param array<string, mixed> $config
     */
    protected function days(array $config, string $key = 'olderThanDays'): int
    {
        return max(0, (int)($config[$key] ?? $this->defaultConfig()[$key] ?? 0));
    }

    /**
     * The cutoff date for a "older than N days" config value, or null when N is 0 — which every
     * sweeper reads as "no age filter", not "everything older than right now".
     */
    protected function cutoff(int $days): ?DateTime
    {
        if ($days <= 0) {
            return null;
        }

        return new DateTime("-$days days");
    }

    /**
     * Counts rows in dry-run mode and deletes them otherwise, from one table and one condition.
     *
     * @param string|array<mixed> $condition
     */
    protected function sweepRows(SweepResult $result, string $table, string|array $condition, bool $dryRun): void
    {
        $count = (int)(new Query())->from($table)->where($condition)->count();

        $result->found += $count;

        if (!$dryRun && $count > 0) {
            $result->removed += Db::delete($table, $condition);
        }
    }

    /**
     * Files under a directory older than the cutoff, optionally keeping the newest few.
     *
     * @return array<int, array{path: string, size: int, modified: int}>
     */
    protected function agedFiles(string $dir, ?DateTime $cutoff, int $keepNewest = 0, ?string $pattern = null): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            if ($pattern !== null && !preg_match($pattern, basename($path))) {
                continue;
            }

            $files[] = [
                'path' => $path,
                'size' => (int)@filesize($path),
                'modified' => (int)@filemtime($path),
            ];
        }

        // Newest first, so "keep the last N" is a slice rather than a sort key nobody can read.
        usort($files, fn(array $a, array $b) => $b['modified'] <=> $a['modified']);

        if ($keepNewest > 0) {
            $files = array_slice($files, $keepNewest);
        }

        if ($cutoff !== null) {
            $timestamp = $cutoff->getTimestamp();
            $files = array_values(array_filter($files, fn(array $f) => $f['modified'] < $timestamp));
        }

        return $files;
    }

    /**
     * Files anywhere beneath a directory, older than the cutoff.
     *
     * The flat {@see agedFiles()} is right for the storage directories Craft writes into
     * directly — backups, logs. The cache and temp directories nest instead, so those need this.
     *
     * @return array<int, array{path: string, size: int, modified: int}>
     */
    protected function agedTreeFiles(string $dir, ?DateTime $cutoff): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $timestamp = $cutoff?->getTimestamp();
        $files = [];

        try {
            foreach (FileHelper::findFiles($dir) as $path) {
                $modified = (int)@filemtime($path);

                if ($timestamp !== null && $modified >= $timestamp) {
                    continue;
                }

                $files[] = [
                    'path' => $path,
                    'size' => (int)@filesize($path),
                    'modified' => $modified,
                ];
            }
        } catch (Throwable) {
            // Unreadable directories are a fact of shared hosting. Reporting nothing beats
            // failing the whole sweep over a permissions bit.
            return [];
        }

        return $files;
    }

    /**
     * Records — and in a real sweep, deletes — a list of files.
     *
     * @param array<int, array{path: string, size: int, modified: int}> $files
     */
    protected function sweepFiles(SweepResult $result, array $files, bool $dryRun): void
    {
        foreach ($files as $file) {
            $result->found++;
            $result->bytes += $file['size'];

            if (count($result->sample) < 10) {
                $result->sample[] = basename($file['path']) . ' (' . Bytes::format($file['size']) . ')';
            }

            if ($dryRun) {
                continue;
            }

            if (@unlink($file['path'])) {
                $result->removed++;
            }
        }
    }

    /**
     * Everything under a directory, recursively — used by the sweepers that empty a cache
     * directory rather than pick files out of it.
     *
     * @return array{0: int, 1: int} File count and total bytes.
     */
    protected function measureTree(string $dir): array
    {
        if (!is_dir($dir)) {
            return [0, 0];
        }

        $count = 0;
        $bytes = 0;

        try {
            foreach (FileHelper::findFiles($dir) as $path) {
                $count++;
                $bytes += (int)@filesize($path);
            }
        } catch (Throwable) {
            // An unreadable directory is a skip, not a failure — shared hosting does this.
            return [0, 0];
        }

        return [$count, $bytes];
    }

    /**
     * Adds a sample line, up to a sensible ceiling. The sample exists so a number can be
     * sanity-checked, not so the whole set can be reviewed.
     */
    protected function addSample(SweepResult $result, string $line): void
    {
        if (count($result->sample) < 10) {
            $result->sample[] = $line;
        }
    }
}
