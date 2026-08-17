<?php

namespace justinholtweb\nuke\events;

use craft\events\CancelableEvent;
use justinholtweb\nuke\models\SweepResult;

/**
 * Raised around a sweep.
 */
class SweepEvent extends CancelableEvent
{
    /** @var string[] Handles of the sweepers taking part. */
    public array $handles = [];

    public bool $dryRun = false;

    /** @var SweepResult[] */
    public array $results = [];

    public ?int $runId = null;
}
