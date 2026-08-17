<?php

namespace justinholtweb\nuke\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\nuke\models\Run;
use justinholtweb\nuke\Plugin;
use justinholtweb\nuke\records\RunRecord;

/**
 * The run ledger.
 *
 * Every strike and every sweep gets a row, opened before the work starts and closed after it
 * finishes. Opening it first is deliberate: a run that crashes half-way still leaves a record
 * saying what it was doing, which is exactly the run somebody will need to reconstruct.
 */
class Runs extends Component
{
    /**
     * Opens a run and returns its id.
     *
     * @param array<string, mixed>|null $target
     * @param array<string, mixed>|null $preview
     */
    public function open(
        string $type,
        string $summary,
        ?array $target = null,
        ?array $preview = null,
        bool $dryRun = false,
        bool $scheduled = false,
        ?string $note = null,
    ): int {
        $record = new RunRecord();
        $record->type = $type;
        $record->status = RunRecord::STATUS_RUNNING;
        $record->summary = $summary;
        $record->note = $note;
        $record->target = $target;
        $record->preview = $preview;
        $record->dryRun = $dryRun;
        $record->scheduled = $scheduled;
        $record->userId = Craft::$app->getUser()->getId();
        $record->save(false);

        return (int)$record->id;
    }

    /**
     * Closes a run with what happened.
     *
     * @param array<string, mixed> $outcome
     */
    public function close(
        int $id,
        array $outcome,
        int $removed,
        int $failed,
        float $duration,
        ?string $backupPath = null,
        string $status = RunRecord::STATUS_DONE,
    ): void {
        $record = RunRecord::findOne($id);

        if ($record === null) {
            return;
        }

        $record->status = $status;
        $record->outcome = $outcome;
        $record->removed = $removed;
        $record->failed = $failed;
        $record->duration = round($duration, 3);
        $record->backupPath = $backupPath;
        $record->save(false);

        $this->prune();
    }

    public function markFailed(int $id, string $message): void
    {
        $record = RunRecord::findOne($id);

        if ($record === null) {
            return;
        }

        $record->status = RunRecord::STATUS_FAILED;
        $record->outcome = ['errors' => [$message]];
        $record->save(false);
    }

    public function get(int $id): ?Run
    {
        $record = RunRecord::findOne($id);

        return $record ? Run::fromRecord($record) : null;
    }

    /**
     * @return Run[]
     */
    public function recent(int $limit = 50, ?string $type = null): array
    {
        $query = RunRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if ($type !== null) {
            $query->where(['type' => $type]);
        }

        return array_map(
            fn(RunRecord $record) => Run::fromRecord($record),
            $query->all(),
        );
    }

    /**
     * Headline numbers for the dashboard.
     *
     * @return array{runs: int, strikes: int, sweeps: int, removed: int, lastSweep: ?Run, lastStrike: ?Run}
     */
    public function stats(): array
    {
        $counts = (new Query())
            ->select(['type', 'n' => 'COUNT(*)', 'removed' => 'SUM([[removed]])'])
            ->from(RunRecord::tableName())
            ->where(['dryRun' => false])
            ->groupBy(['type'])
            ->all();

        $strikes = 0;
        $sweeps = 0;
        $removed = 0;

        foreach ($counts as $row) {
            $removed += (int)$row['removed'];

            if ($row['type'] === RunRecord::TYPE_STRIKE) {
                $strikes = (int)$row['n'];
            } else {
                $sweeps = (int)$row['n'];
            }
        }

        return [
            'runs' => $strikes + $sweeps,
            'strikes' => $strikes,
            'sweeps' => $sweeps,
            'removed' => $removed,
            'lastSweep' => $this->latest(RunRecord::TYPE_SWEEP),
            'lastStrike' => $this->latest(RunRecord::TYPE_STRIKE),
        ];
    }

    public function latest(string $type): ?Run
    {
        $record = RunRecord::find()
            ->where(['type' => $type, 'dryRun' => false])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return $record ? Run::fromRecord($record) : null;
    }

    public function delete(int $id): void
    {
        RunRecord::deleteAll(['id' => $id]);
    }

    /**
     * Trims the ledger to the configured length.
     *
     * Deletes by id rather than by date: two runs finishing in the same second would otherwise be
     * an ambiguous cut, and the ledger is one of the few places on a site where "which of these
     * two happened first" is a question somebody actually asks.
     */
    public function prune(): int
    {
        /** @var \justinholtweb\nuke\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $keep = max(1, $settings->retainRuns);

        $cutoff = (new Query())
            ->select(['id'])
            ->from(RunRecord::tableName())
            ->orderBy(['id' => SORT_DESC])
            ->offset($keep - 1)
            ->limit(1)
            ->scalar();

        if ($cutoff === false || $cutoff === null) {
            return 0;
        }

        return Db::delete(RunRecord::tableName(), ['<', 'id', (int)$cutoff]);
    }
}
