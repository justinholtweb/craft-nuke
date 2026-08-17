<?php

namespace justinholtweb\nuke\events;

use yii\base\Event;

/**
 * Lets a plugin add its own element type to the list a strike can be aimed at.
 *
 * @see \justinholtweb\nuke\services\Scopes::EVENT_REGISTER_SCOPES
 */
class RegisterScopesEvent extends Event
{
    /**
     * @var array<class-string<\justinholtweb\nuke\targets\ScopeInterface>|\justinholtweb\nuke\targets\ScopeInterface>
     */
    public array $scopes = [];
}
