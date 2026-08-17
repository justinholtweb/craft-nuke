<?php

namespace justinholtweb\nuke\events;

use yii\base\Event;

/**
 * Lets a plugin add its own housekeeping task to the sweep.
 *
 * @see \justinholtweb\nuke\services\Sweepers::EVENT_REGISTER_SWEEPERS
 */
class RegisterSweepersEvent extends Event
{
    /**
     * @var array<class-string<\justinholtweb\nuke\sweepers\SweeperInterface>|\justinholtweb\nuke\sweepers\SweeperInterface>
     */
    public array $sweepers = [];
}
