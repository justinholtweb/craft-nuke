<?php

namespace justinholtweb\nuke\services;

use Craft;
use craft\base\Component;
use justinholtweb\nuke\events\RegisterScopesEvent;
use justinholtweb\nuke\models\Target;
use justinholtweb\nuke\targets\AssetScope;
use justinholtweb\nuke\targets\CategoryScope;
use justinholtweb\nuke\targets\EntryScope;
use justinholtweb\nuke\targets\ScopeInterface;
use justinholtweb\nuke\targets\TagScope;
use justinholtweb\nuke\targets\UserScope;
use yii\base\InvalidArgumentException;

/**
 * The register of things a strike can be aimed at.
 */
class Scopes extends Component
{
    /**
     * @event RegisterScopesEvent Raised when the scope list is being assembled.
     */
    public const EVENT_REGISTER_SCOPES = 'registerScopes';

    /** @var ScopeInterface[]|null Keyed by handle. */
    private ?array $scopes = null;

    /**
     * Every registered scope, whether or not the site can use it.
     *
     * @return ScopeInterface[] Keyed by handle.
     */
    public function all(): array
    {
        if ($this->scopes !== null) {
            return $this->scopes;
        }

        $event = new RegisterScopesEvent([
            'scopes' => [
                EntryScope::class,
                CategoryScope::class,
                TagScope::class,
                AssetScope::class,
                UserScope::class,
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_SCOPES, $event);

        $this->scopes = [];

        foreach ($event->scopes as $scope) {
            if (is_string($scope)) {
                $scope = Craft::createObject($scope);
            }

            if (!$scope instanceof ScopeInterface) {
                continue;
            }

            $this->scopes[$scope::handle()] = $scope;
        }

        return $this->scopes;
    }

    /**
     * The scopes this site can actually target — the ones with somewhere to point.
     *
     * @return ScopeInterface[] Keyed by handle.
     */
    public function available(): array
    {
        return array_filter($this->all(), fn(ScopeInterface $scope) => $scope->isAvailable());
    }

    public function get(string $handle): ScopeInterface
    {
        $scopes = $this->all();

        if (!isset($scopes[$handle])) {
            throw new InvalidArgumentException("No Nuke scope with the handle “{$handle}”.");
        }

        return $scopes[$handle];
    }

    public function has(string $handle): bool
    {
        return isset($this->all()[$handle]);
    }

    /**
     * What the operator has to type to arm a strike against this target.
     *
     * A single named source gives its own handle, which is the useful case: typing `news` to
     * delete the News section is a check that you are deleting the section you think you are.
     * With several sources, or none, there is no one handle to name, so it falls back to the
     * scope — and the phrase becomes a deliberate act rather than a specific one.
     */
    public function confirmationLabel(Target $target): string
    {
        $sourceIds = $target->ids('sourceIds');

        if (count($sourceIds) !== 1) {
            return $target->scope;
        }

        $handles = $this->get($target->scope)->sourceHandles($target);

        return $handles[0] ?? $target->scope;
    }
}
