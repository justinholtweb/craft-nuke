<?php

namespace justinholtweb\nuke\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\nuke\models\Target;
use justinholtweb\nuke\Plugin;

/**
 * Runs a strike in the background.
 *
 * The job carries the target's *attributes*, not the object. A queue job is serialised into the
 * database and may not be picked up for minutes, by which point the class it was serialised from
 * could have gained or lost a property in a plugin update — attributes rehydrate through the
 * model's own defaults and survive that.
 */
class StrikeJob extends BaseJob
{
    /** @var array<string, mixed> */
    public array $target = [];

    /** @var array<string, mixed>|null The preview the operator approved. */
    public ?array $preview = null;

    public function execute($queue): void
    {
        $target = new Target();
        $target->setAttributes($this->target, false);

        $this->setProgress($queue, 0, Craft::t('nuke', 'Preparing'));

        Plugin::getInstance()->detonator->launch(
            $target,
            null,
            function(int $done, int $total) use ($queue) {
                $this->setProgress(
                    $queue,
                    $total > 0 ? $done / $total : 1,
                    Craft::t('nuke', '{done} of {total}', ['done' => $done, 'total' => $total]),
                );
            },
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('nuke', 'Running a Nuke strike');
    }
}
