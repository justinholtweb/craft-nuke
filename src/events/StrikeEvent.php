<?php

namespace justinholtweb\nuke\events;

use craft\events\CancelableEvent;
use justinholtweb\nuke\models\BlastRadius;
use justinholtweb\nuke\models\StrikeOutcome;
use justinholtweb\nuke\models\Target;

/**
 * Raised around a strike.
 *
 * The `before` variant is cancellable — setting `$isValid = false` stops the deletion, which is
 * the hook a site would use to enforce its own policy ("never during business hours", "not this
 * section without a ticket number").
 */
class StrikeEvent extends CancelableEvent
{
    public Target $target;
    public ?BlastRadius $blastRadius = null;
    public ?StrikeOutcome $outcome = null;

    /** @var int|null The run this belongs to, once one exists. */
    public ?int $runId = null;
}
