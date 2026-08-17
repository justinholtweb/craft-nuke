<?php

namespace justinholtweb\nuke\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\models\SweepResult;
use justinholtweb\nuke\Plugin;
use yii\console\ExitCode;

/**
 * Housekeeping from the command line — the half of the plugin meant to live in a crontab.
 *
 * ```
 * 0 3 * * *     php craft nuke/sweep/run --dry-run=0   # cron owns the schedule
 * *\/15 * * * *  php craft nuke/sweep/due --dry-run=0   # Nuke owns the schedule
 * ```
 */
class SweepController extends Controller
{
    public $defaultAction = 'run';

    /** @var bool Report what would be removed without removing it. */
    public bool $dryRun = true;

    /** @var string|null Comma-separated sweeper handles. Defaults to every enabled one. */
    public ?string $only = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'only']);
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Let Craft's garbage collector narrate. Someone watching a command run wants to see
        // what it is doing; a queue worker's log does not.
        Plugin::getInstance()->sweep->verbose = true;

        return true;
    }

    /**
     * Lists the available sweepers and whether each is switched on.
     */
    public function actionList(): int
    {
        $registry = Plugin::getInstance()->sweepers;
        $labels = $registry->groupLabels();
        $currentGroup = null;

        foreach ($registry->all() as $handle => $sweeper) {
            if ($sweeper->group() !== $currentGroup) {
                $currentGroup = $sweeper->group();
                $this->stdout("\n" . ($labels[$currentGroup] ?? $currentGroup) . "\n", Console::BOLD);
            }

            $config = $registry->configFor($sweeper);
            $on = !empty($config['enabled']) && $sweeper->isAvailable();

            $this->stdout('  ' . ($on ? '●' : '○') . ' ', $on ? Console::FG_GREEN : Console::FG_GREY);
            $this->stdout(sprintf('%-20s', $handle), Console::FG_YELLOW);
            $this->stdout($sweeper->label() . "\n");
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Scans, or sweeps.
     */
    public function actionRun(): int
    {
        $plugin = Plugin::getInstance();
        $handles = $this->handles();

        $result = $this->dryRun
            ? ['runId' => null, 'results' => $plugin->sweep->scan($handles)]
            : $plugin->sweep->run($handles);

        $this->renderResults($result['results']);

        if ($this->dryRun) {
            $this->stdout("\nDry run — nothing was removed. Pass --dry-run=0 to run it.\n", Console::FG_GREEN);
        } elseif ($result['runId'] !== null) {
            $this->stdout("\nRun #{$result['runId']}\n", Console::FG_GREEN);
        }

        $failed = count(array_filter($result['results'], fn(SweepResult $r) => $r->failed));

        return $failed > 0 ? ExitCode::SOFTWARE : ExitCode::OK;
    }

    /**
     * Runs the scheduled sweep, but only if it is actually due.
     *
     * Meant for a crontab that fires often — every fifteen minutes, say — where the schedule
     * itself lives in Nuke's settings rather than in cron. Exits 0 either way; "not due" is not
     * an error, and a cron job that reported failure four times an hour would be turned off
     * within a week.
     *
     * `--dry-run` applies here too, and is on by default like everywhere else in this controller,
     * so the crontab line is `nuke/sweep/due --dry-run=0`. A dry run leaves no ledger row, so it
     * does not count as the scheduled sweep having happened — the next real run is still owed.
     */
    public function actionDue(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            $this->stderr("Scheduled sweeps need Nuke Pro.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!$plugin->schedules->isDue()) {
            $this->stdout('Not due. Next: ' . $plugin->schedules->nextOccurrence()->format('Y-m-d H:i') . "\n");
            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->renderResults($plugin->sweep->scan());
            $this->stdout("\nDue, but this was a dry run — nothing was removed and the sweep is still owed.\n", Console::FG_YELLOW);
            $this->stdout("Add --dry-run=0 to the crontab line.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $result = $plugin->sweep->run(null, true);
        $this->renderResults($result['results']);
        $this->stdout("\nRun #{$result['runId']}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * @return string[]|null
     */
    private function handles(): ?array
    {
        if ($this->only === null) {
            return null;
        }

        $registry = Plugin::getInstance()->sweepers;

        return array_values(array_filter(
            array_map('trim', explode(',', $this->only)),
            fn(string $handle) => $registry->has($handle),
        ));
    }

    /**
     * Not called `render()`: `yii\base\Controller::render()` is public, and a private override
     * of it is a fatal compile error the moment the class is autoloaded.
     *
     * @param SweepResult[] $results
     */
    private function renderResults(array $results): void
    {
        $this->stdout("\n");
        $totalBytes = 0;

        foreach ($results as $result) {
            $n = $result->dryRun ? $result->found : $result->removed;
            $totalBytes += $result->bytes;

            if ($result->failed) {
                $this->stdout(sprintf("  ✗ %-24s %s\n", $result->handle, $result->message), Console::FG_RED);
                continue;
            }

            if ($result->skipped) {
                $this->stdout(sprintf("  – %-24s %s\n", $result->handle, $result->message), Console::FG_GREY);
                continue;
            }

            $suffix = $result->bytes > 0 ? ' (' . Bytes::format($result->bytes) . ')' : '';

            if ($result->ran) {
                $this->stdout(sprintf("  ✓ %-24s %s\n", $result->handle, $result->message), Console::FG_GREEN);
                continue;
            }

            $this->stdout(
                sprintf("  %s %-24s %s%s\n", $n > 0 ? '✓' : ' ', $result->handle, number_format($n), $suffix),
                $n > 0 ? Console::FG_GREEN : Console::FG_GREY,
            );
        }

        if ($totalBytes > 0) {
            $this->stdout("\n  " . Bytes::format($totalBytes) . " of files.\n");
        }
    }
}
