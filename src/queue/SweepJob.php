<?php

namespace justinholtweb\nuke\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\nuke\Plugin;

/**
 * Runs a housekeeping sweep in the background.
 *
 * This is also what the scheduler pushes, which is why `scheduled` is a property rather than
 * being inferred: a sweep queued by the schedule and one queued by a person are the same work,
 * and the ledger should still be able to tell them apart.
 */
class SweepJob extends BaseJob
{
    /** @var string[]|null Sweepers to run, or null for every enabled one. */
    public ?array $handles = null;

    public bool $scheduled = false;

    public function execute($queue): void
    {
        Plugin::getInstance()->sweep->run(
            $this->handles,
            $this->scheduled,
            function(int $done, int $total, string $label) use ($queue) {
                $this->setProgress($queue, $total > 0 ? $done / $total : 1, $label);
            },
        );
    }

    protected function defaultDescription(): ?string
    {
        return $this->scheduled
            ? Craft::t('nuke', 'Running the scheduled Nuke sweep')
            : Craft::t('nuke', 'Running a Nuke sweep');
    }
}
