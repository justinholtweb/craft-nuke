<?php

namespace justinholtweb\nuke\variables;

use justinholtweb\nuke\models\Run;
use justinholtweb\nuke\Plugin;

/**
 * `craft.nuke` — a read-only view of the run ledger and the schedule.
 *
 * Nothing here deletes anything, and nothing ever will. A Twig template rendered by a visitor's
 * request is not a place from which content should be removable.
 */
class NukeVariable
{
    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return Plugin::getInstance()->runs->stats();
    }

    /**
     * @return Run[]
     */
    public function runs(int $limit = 10, ?string $type = null): array
    {
        return Plugin::getInstance()->runs->recent($limit, $type);
    }

    public function lastSweep(): ?Run
    {
        return Plugin::getInstance()->runs->latest(\justinholtweb\nuke\records\RunRecord::TYPE_SWEEP);
    }

    public function nextSweep(): ?string
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro() || !$plugin->getSettings()->scheduleEnabled) {
            return null;
        }

        return $plugin->schedules->nextOccurrence()->format('c');
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }
}
