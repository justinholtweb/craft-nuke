<?php

namespace justinholtweb\nuke\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\nuke\records\RunRecord;

/**
 * A row in the run ledger, in a shape templates can read.
 *
 * The ledger is the plugin's memory. Once a strike has run there is, by design, nothing left to
 * inspect — so what was intended, what the preview said, and what actually happened all have to
 * be written down at the time or they are gone.
 */
class Run extends Model
{
    public ?int $id = null;
    public string $type = RunRecord::TYPE_STRIKE;
    public string $status = RunRecord::STATUS_PENDING;
    public ?string $summary = null;
    public ?string $note = null;

    /** @var array<string, mixed>|null The target, as it was submitted. */
    public ?array $target = null;

    /** @var array<string, mixed>|null The blast radius or sweep scan, as it was previewed. */
    public ?array $preview = null;

    /** @var array<string, mixed>|null What actually happened. */
    public ?array $outcome = null;

    public ?string $backupPath = null;
    public int $removed = 0;
    public int $failed = 0;
    public float $duration = 0.0;
    public bool $dryRun = false;
    public bool $scheduled = false;
    public ?int $userId = null;
    public ?DateTime $dateCreated = null;

    public function isStrike(): bool
    {
        return $this->type === RunRecord::TYPE_STRIKE;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [RunRecord::STATUS_DONE, RunRecord::STATUS_FAILED, RunRecord::STATUS_CANCELLED], true);
    }

    public function getUser(): ?User
    {
        return $this->userId ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    public function getCpUrl(): string
    {
        return UrlHelper::cpUrl("nuke/runs/$this->id");
    }

    /**
     * The label shown against the run in listings.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            RunRecord::STATUS_PENDING => Craft::t('nuke', 'Queued'),
            RunRecord::STATUS_RUNNING => Craft::t('nuke', 'Running'),
            RunRecord::STATUS_DONE => $this->failed > 0
                ? Craft::t('nuke', 'Finished with errors')
                : Craft::t('nuke', 'Finished'),
            RunRecord::STATUS_FAILED => Craft::t('nuke', 'Failed'),
            RunRecord::STATUS_CANCELLED => Craft::t('nuke', 'Cancelled'),
            default => $this->status,
        };
    }

    /**
     * Craft's own status colour vocabulary, so the ledger reads like the rest of the control panel.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            RunRecord::STATUS_PENDING => 'orange',
            RunRecord::STATUS_RUNNING => 'blue',
            RunRecord::STATUS_DONE => $this->failed > 0 ? 'orange' : 'green',
            RunRecord::STATUS_FAILED => 'red',
            default => 'grey',
        };
    }

    /**
     * The difference between what the preview promised and what the run delivered.
     *
     * Null when there is nothing to compare — a dry run, or a run that never previewed. Zero is
     * meaningful and is not null.
     */
    public function drift(): ?int
    {
        if ($this->dryRun || $this->preview === null || $this->outcome === null) {
            return null;
        }

        $predicted = (int)($this->preview['total'] ?? 0);

        return $this->removed - $predicted;
    }

    public static function fromRecord(RunRecord $record): self
    {
        return new self([
            'id' => (int)$record->id,
            'type' => $record->type,
            'status' => $record->status,
            'summary' => $record->summary,
            'note' => $record->note,
            'target' => $record->target,
            'preview' => $record->preview,
            'outcome' => $record->outcome,
            'backupPath' => $record->backupPath,
            'removed' => (int)$record->removed,
            'failed' => (int)$record->failed,
            'duration' => (float)$record->duration,
            'dryRun' => (bool)$record->dryRun,
            'scheduled' => (bool)$record->scheduled,
            'userId' => $record->userId !== null ? (int)$record->userId : null,
            // Craft stores datetimes in UTC and Active Record hands them back as plain strings,
            // so the zone has to be supplied — without it PHP reads a UTC timestamp as local and
            // every run in the ledger is off by the server's offset.
            'dateCreated' => new DateTime((string)$record->dateCreated, new DateTimeZone('UTC')),
        ]);
    }
}
