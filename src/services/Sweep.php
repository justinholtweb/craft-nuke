<?php

namespace justinholtweb\nuke\services;

use Craft;
use craft\base\Component;
use justinholtweb\nuke\events\SweepEvent;
use justinholtweb\nuke\models\SweepResult;
use justinholtweb\nuke\Plugin;
use justinholtweb\nuke\records\RunRecord;
use justinholtweb\nuke\sweepers\GarbageCollectionSweeper;
use justinholtweb\nuke\sweepers\SweeperInterface;
use Throwable;

/**
 * Runs housekeeping sweeps.
 *
 * A sweep is a sequence of independent tasks, and it treats them that way: one sweeper failing
 * does not stop the others, because there is no reason a broken log directory should prevent the
 * database work. Failures are collected and reported rather than thrown.
 */
class Sweep extends Component
{
    /**
     * @event SweepEvent Raised before a sweep runs. Cancellable via `$isValid`.
     */
    public const EVENT_BEFORE_SWEEP = 'beforeSweep';

    /**
     * @event SweepEvent Raised once a sweep has finished.
     */
    public const EVENT_AFTER_SWEEP = 'afterSweep';

    /**
     * @var bool Whether sweepers may narrate to stdout.
     *
     * Only Nuke's own console commands turn this on. Craft's garbage collector writes a line per
     * table it touches, which is what you want from `craft nuke/sweep/run` and emphatically not
     * what you want from a queue worker — `craft queue/listen` is a console request too, so
     * "am I on the console?" is the wrong question to ask.
     */
    public bool $verbose = false;

    /**
     * What a sweep would remove.
     *
     * @param string[]|null $handles Sweepers to include, or null for every enabled one.
     * @return SweepResult[] Keyed by handle.
     */
    public function scan(?array $handles = null): array
    {
        return $this->execute($handles, true, false)['results'];
    }

    /**
     * Runs a sweep and records it.
     *
     * @param string[]|null $handles
     * @return array{runId: int|null, results: SweepResult[]}
     */
    public function run(?array $handles = null, bool $scheduled = false, ?callable $onProgress = null): array
    {
        return $this->execute($handles, false, $scheduled, $onProgress);
    }

    /**
     * @param string[]|null $handles
     * @return array{runId: int|null, results: SweepResult[]}
     */
    private function execute(?array $handles, bool $dryRun, bool $scheduled, ?callable $onProgress = null): array
    {
        $started = microtime(true);
        $sweepers = $this->resolve($handles);

        $event = new SweepEvent([
            'handles' => array_keys($sweepers),
            'dryRun' => $dryRun,
        ]);
        $this->trigger(self::EVENT_BEFORE_SWEEP, $event);

        if (!$event->isValid) {
            return ['runId' => null, 'results' => []];
        }

        $runs = Plugin::getInstance()->runs;
        $runId = null;

        // Dry runs don't get a ledger row. Scanning is something an operator does repeatedly
        // while narrowing a configuration, and 40 rows saying "nothing was deleted" would push
        // the runs that did delete something off the end of the ledger.
        if (!$dryRun) {
            $runId = $runs->open(
                RunRecord::TYPE_SWEEP,
                Craft::t('nuke', '{n, plural, =1{Sweep of one task} other{Sweep of # tasks}}', ['n' => count($sweepers)]),
                ['sweepers' => array_keys($sweepers)],
                null,
                false,
                $scheduled,
            );
        }

        $results = [];
        $index = 0;
        $total = count($sweepers);

        foreach ($sweepers as $handle => $sweeper) {
            $config = Plugin::getInstance()->sweepers->configFor($sweeper);

            $results[$handle] = $dryRun
                ? $sweeper->scan($config)
                : $sweeper->sweep($config);

            $index++;

            if ($onProgress !== null) {
                $onProgress($index, $total, $sweeper->label());
            }
        }

        $duration = microtime(true) - $started;
        $removed = array_sum(array_map(fn(SweepResult $r) => $r->removed, $results));
        $failed = count(array_filter($results, fn(SweepResult $r) => $r->failed));

        if ($runId !== null) {
            $runs->close(
                $runId,
                ['results' => $this->serialise($results), 'bytes' => $this->bytes($results)],
                $removed,
                $failed,
                $duration,
            );

            $this->log($results, $removed, $failed, $duration, $scheduled);

            try {
                Plugin::getInstance()->notifications->sweepFinished($runId, $results);
            } catch (Throwable $e) {
                // A mail server that is down is not a reason to report the sweep as failed —
                // the sweep succeeded, and the ledger already says so.
                Craft::error("Sweep notification failed: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            }
        }

        $this->trigger(self::EVENT_AFTER_SWEEP, new SweepEvent([
            'handles' => array_keys($sweepers),
            'dryRun' => $dryRun,
            'results' => $results,
            'runId' => $runId,
        ]));

        return ['runId' => $runId, 'results' => $results];
    }

    /**
     * Works out which sweepers to run, in run order, with the garbage collector kept last.
     *
     * @param string[]|null $handles
     * @return SweeperInterface[] Keyed by handle.
     */
    private function resolve(?array $handles): array
    {
        $registry = Plugin::getInstance()->sweepers;

        if ($handles === null) {
            $sweepers = $registry->enabled();
        } else {
            $wanted = array_flip($handles);
            // Filtering the registry rather than looking each handle up preserves run order,
            // which the caller has no reason to know about and every reason to depend on.
            $sweepers = array_filter(
                $registry->available(),
                fn(SweeperInterface $s) => isset($wanted[$s::handle()]),
            );
        }

        // Belt and braces: whatever order the registry or the caller produced, Craft's collector
        // runs at the end, because everything above it makes work for it.
        if (isset($sweepers[GarbageCollectionSweeper::handle()])) {
            $gc = $sweepers[GarbageCollectionSweeper::handle()];
            unset($sweepers[GarbageCollectionSweeper::handle()]);
            $sweepers[GarbageCollectionSweeper::handle()] = $gc;
        }

        return $sweepers;
    }

    /**
     * @param SweepResult[] $results
     * @return array<string, array<string, mixed>>
     */
    private function serialise(array $results): array
    {
        $out = [];

        foreach ($results as $handle => $result) {
            $out[$handle] = [
                'label' => $result->label,
                'group' => $result->group,
                'found' => $result->found,
                'removed' => $result->removed,
                'bytes' => $result->bytes,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
                'ran' => $result->ran,
                'message' => $result->message,
                'sample' => $result->sample,
                'duration' => round($result->duration, 3),
            ];
        }

        return $out;
    }

    /**
     * @param SweepResult[] $results
     */
    private function bytes(array $results): int
    {
        return array_sum(array_map(fn(SweepResult $r) => $r->bytes, $results));
    }

    /**
     * @param SweepResult[] $results
     */
    private function log(array $results, int $removed, int $failed, float $duration, bool $scheduled): void
    {
        $notable = [];

        foreach ($results as $handle => $result) {
            if ($result->removed > 0 || $result->failed) {
                $notable[] = sprintf('%s=%d%s', $handle, $result->removed, $result->failed ? ' (failed)' : '');
            }
        }

        Craft::info(sprintf(
            '%s sweep finished: %d removed, %d failed, %.1fs%s',
            $scheduled ? 'Scheduled' : 'Manual',
            $removed,
            $failed,
            $duration,
            $notable ? ' — ' . implode(', ', $notable) : '',
        ), Plugin::LOG_CATEGORY);
    }
}
