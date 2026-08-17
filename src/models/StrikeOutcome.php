<?php

namespace justinholtweb\nuke\models;

use yii\base\BaseObject;

/**
 * What a strike actually did, as opposed to what its preview said it would.
 *
 * The two are compared on the run detail screen. They differ legitimately — content changes
 * between preview and execution, and an element can fail to delete — and a difference that
 * nobody can see is a difference nobody investigates.
 */
class StrikeOutcome extends BaseObject
{
    public int $elements = 0;
    public int $drafts = 0;
    public int $revisions = 0;
    public int $relations = 0;

    /** @var int Elements whose deletion was refused or threw. */
    public int $failed = 0;

    /** @var int Bytes of asset files removed. */
    public int $bytes = 0;

    /** @var string[] Messages from individual failures, capped so one broken batch can't fill the ledger row. */
    public array $errors = [];

    /** @var string|null Path of the backup taken before the strike, if one was. */
    public ?string $backupPath = null;

    /** @var bool Whether Craft's garbage collector ran afterwards. */
    public bool $gcRan = false;

    /** @var float Seconds the strike took. */
    public float $duration = 0.0;

    /** @var bool Whether the run stopped early because too many deletions failed. */
    public bool $abortedEarly = false;

    public function total(): int
    {
        return $this->elements + $this->drafts + $this->revisions;
    }

    /**
     * Records a failure, keeping only the first few messages.
     *
     * A strike over 50,000 elements against a broken foreign key would otherwise write 50,000
     * near-identical strings into a JSON column. The count is what matters after the first
     * handful; the log has all of them.
     */
    public function fail(string $message): void
    {
        $this->failed++;

        if (count($this->errors) < 20) {
            $this->errors[] = $message;
        }
    }
}
