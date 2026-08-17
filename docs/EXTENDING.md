# Extending Nuke

Two registries and four events. Everything Nuke can delete goes through one of the registries, so
adding your own element type or your own housekeeping task is the same shape of work as the ones
that ship.

## A custom scope

A scope is a kind of content a strike can be aimed at — one per element type. Implement
`ScopeInterface`, or extend `BaseScope` and get the filters that mean the same thing for every
element type (status, sites, dates, search, trashed, limit) for free.

```php
use craft\elements\db\ElementQuery;
use justinholtweb\nuke\models\Target;
use justinholtweb\nuke\targets\BaseScope;
use mynamespace\elements\Product;

class ProductScope extends BaseScope
{
    public static function handle(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function elementType(): string
    {
        return Product::class;
    }

    public function sourceLabel(): string
    {
        return 'Product Types';
    }

    public function sourceOptions(): array
    {
        // Anything with an id, name and handle.
        return $this->optionsFrom(MyPlugin::getInstance()->productTypes->getAll());
    }

    protected function baseQuery(Target $target): ElementQuery
    {
        $query = Product::find();

        if ($typeIds = $target->ids('sourceIds')) {
            $query->typeId($typeIds);
        }

        return $query;
    }
}
```

Register it:

```php
use justinholtweb\nuke\events\RegisterScopesEvent;
use justinholtweb\nuke\services\Scopes;
use yii\base\Event;

Event::on(Scopes::class, Scopes::EVENT_REGISTER_SCOPES, function(RegisterScopesEvent $event) {
    $event->scopes[] = ProductScope::class;
});
```

Three optional methods are worth knowing about:

- **`statusOptions()`** — override it if your element type has statuses beyond enabled/disabled.
  This matters more than it looks: asking an element query for a status its type does not have
  returns *nothing at all* rather than erroring, which is indistinguishable from "no matches".
- **`warnings(Target $target)`** — things the operator should read before firing that are not
  reasons to refuse. The asset scope uses it to say whether files leave the volume.
- **`validate(Target $target, Settings $settings)`** — reasons the strike must not run. Call
  `parent::validate()` to keep the protected-scope check.
- **`hasStructure(Target $target)`** — return true if your elements can be hierarchical, and the
  preview will spend a query counting the descendants that get re-parented rather than deleted.

## A custom sweeper

A sweeper is one housekeeping task. Extend `BaseSweeper` and implement `execute()` once — the
scan and the sweep both run through it, which is what stops a preview and an execution drifting
apart.

```php
use justinholtweb\nuke\models\SweepResult;
use justinholtweb\nuke\sweepers\BaseSweeper;

class ExpiredCartsSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'expiredCarts';
    }

    public function label(): string
    {
        return 'Expired carts';
    }

    public function description(): string
    {
        // Say what it removes *and* what it deliberately leaves alone. This is what an operator
        // reads before switching it on.
        return 'Deletes abandoned carts older than the window. Completed orders are never touched.';
    }

    public function group(): string
    {
        return self::GROUP_DATABASE;
    }

    public function defaultConfig(): array
    {
        return ['enabled' => true, 'olderThanDays' => 90];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'olderThanDays',
                'label' => 'Older than',
                'type' => 'days',
                'min' => 1,
                'max' => 3650,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        // `sweepRows()` counts in dry-run mode and deletes otherwise, from one condition.
        $this->sweepRows($result, '{{%mycarts}}', [
            '<', 'dateUpdated', Db::prepareDateForDb($this->cutoff($this->days($config))),
        ], $dryRun);
    }
}
```

Register it the same way, with `Sweepers::EVENT_REGISTER_SWEEPERS`. Registration order is run order,
except that Craft's own garbage collector is always moved to the end.

Helpers available on `BaseSweeper`:

| Method | What it does |
| --- | --- |
| `days($config, $key)` | Reads a config value as a positive int, falling back to your default |
| `cutoff($days)` | The cutoff `DateTime`, or `null` when days is 0 |
| `sweepRows($result, $table, $condition, $dryRun)` | Count, or delete, rows matching a condition |
| `agedFiles($dir, $cutoff, $keepNewest, $pattern)` | Files directly inside a directory |
| `agedTreeFiles($dir, $cutoff)` | Files anywhere beneath a directory |
| `sweepFiles($result, $files, $dryRun)` | Record — and in a real sweep, delete — a file list |
| `addSample($result, $line)` | Add a sample line, up to a sensible ceiling |

If your sweeper's work isn't measured in things removed, set `$result->ran = true` so it still
appears on a report that lists only what changed.

A sweeper that throws does not take the sweep down with it; the failure is recorded against that
task and the rest carry on.

## Events

```php
use justinholtweb\nuke\events\StrikeEvent;
use justinholtweb\nuke\events\SweepEvent;
use justinholtweb\nuke\services\Detonator;
use justinholtweb\nuke\services\Sweep;
```

| Event | When | Cancellable |
| --- | --- | --- |
| `Detonator::EVENT_BEFORE_STRIKE` | Before a strike deletes anything | yes |
| `Detonator::EVENT_AFTER_STRIKE` | After it finishes | no |
| `Sweep::EVENT_BEFORE_SWEEP` | Before a sweep runs | yes |
| `Sweep::EVENT_AFTER_SWEEP` | After it finishes | no |

The cancellable ones are how a site enforces its own policy — never during business hours, not this
section without a ticket number:

```php
Event::on(Detonator::class, Detonator::EVENT_BEFORE_STRIKE, function(StrikeEvent $event) {
    if ((int)date('G') >= 9 && (int)date('G') < 18) {
        $event->isValid = false;
    }
});
```

## Reading the ledger from Twig

`craft.nuke` is read-only, and always will be. A template rendered by a visitor's request is not a
place from which content should be removable.

```twig
{% set last = craft.nuke.lastSweep() %}
{% if last %}
    Last swept {{ last.dateCreated|date('j M') }}, removing {{ last.removed }}.
{% endif %}

{{ craft.nuke.stats().removed }}
{{ craft.nuke.nextSweep() }}
{% for run in craft.nuke.runs(5, 'strike') %}{{ run.summary }}{% endfor %}
```
