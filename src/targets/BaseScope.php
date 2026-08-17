<?php

namespace justinholtweb\nuke\targets;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\models\Target;
use yii\base\BaseObject;

/**
 * Shared behaviour for every scope: the filters that mean the same thing whatever is being
 * deleted, and the checks that apply to all of them.
 *
 * Subclasses supply {@see baseQuery()} — the part that is genuinely element-type-specific — and
 * everything else is layered on identically, so a filter can't work on entries and quietly do
 * nothing on assets.
 */
abstract class BaseScope extends BaseObject implements ScopeInterface
{
    /**
     * A query narrowed to the scope's sources, with no other filters applied.
     */
    abstract protected function baseQuery(Target $target): ElementQuery;

    public function isAvailable(): bool
    {
        return $this->sourceOptions() !== [];
    }

    public function typeOptions(array $sourceIds = []): array
    {
        return [];
    }

    public function statusOptions(): array
    {
        return [
            ['label' => Craft::t('nuke', 'Any status'), 'value' => Target::STATUS_ANY],
            ['label' => Craft::t('nuke', 'Enabled'), 'value' => Element::STATUS_ENABLED],
            ['label' => Craft::t('nuke', 'Disabled'), 'value' => Element::STATUS_DISABLED],
        ];
    }

    public function query(Target $target): ElementQuery
    {
        $query = $this->baseQuery($target);

        // An explicit ID list is the whole target. Applying the filters on top would let a run
        // re-created from a previous one silently match a different set.
        if ($target->elementIds !== []) {
            return $query
                ->id($target->ids('elementIds'))
                ->status(null)
                ->trashed($target->includeTrashed ? null : false)
                ->siteId('*')
                ->unique()
                ->limit(null);
        }

        $this->applyStatus($query, $target);
        $this->applySites($query, $target);
        $this->applyDates($query, $target);

        if ($target->search !== null && trim($target->search) !== '') {
            $query->search(trim($target->search));
        }

        // `trashed(false)` is the default, but stating it makes the intent legible next to the
        // branch that widens it: `null` means "trashed or not", not "only trashed".
        $query->trashed($target->includeTrashed ? null : false);
        $query->limit($target->limit);

        return $query;
    }

    /**
     * Statuses are per-element-type, and `status(null)` is the only portable "everything".
     */
    protected function applyStatus(ElementQuery $query, Target $target): void
    {
        if ($target->status === Target::STATUS_ANY || $target->status === '') {
            $query->status(null);
            return;
        }

        $query->status($target->status);
    }

    /**
     * With no sites named, every site is searched and each element is returned once.
     *
     * Without `unique()` a three-site element counts three times, and a preview that says 3,000
     * where it means 1,000 is worse than no preview.
     */
    protected function applySites(ElementQuery $query, Target $target): void
    {
        $siteIds = $target->ids('siteIds');

        if ($siteIds === []) {
            $query->siteId('*')->unique();
            return;
        }

        $query->siteId($siteIds);

        if (count($siteIds) > 1) {
            $query->unique();
        }
    }

    protected function applyDates(ElementQuery $query, Target $target): void
    {
        $updated = $target->dateFor('updatedBefore');

        if ($updated !== null) {
            $query->dateUpdated('< ' . Db::prepareDateForDb($updated));
        }

        $created = $target->dateFor('createdBefore');

        if ($created !== null) {
            $query->dateCreated('< ' . Db::prepareDateForDb($created));
        }
    }

    public function sourceHandles(Target $target): array
    {
        $wanted = $target->ids('sourceIds');
        $handles = [];

        foreach ($this->sourceOptions() as $option) {
            if ($wanted === [] || in_array($option['value'], $wanted, true)) {
                $handles[] = $option['handle'];
            }
        }

        return $handles;
    }

    public function describe(Target $target): string
    {
        $parts = [];
        $sourceIds = $target->ids('sourceIds');

        if ($sourceIds === []) {
            $parts[] = Craft::t('nuke', 'all {things} on the site', ['things' => strtolower($this->label())]);
        } else {
            $names = [];

            foreach ($this->sourceOptions() as $option) {
                if (in_array($option['value'], $sourceIds, true)) {
                    $names[] = $option['label'];
                }
            }

            $parts[] = Craft::t('nuke', '{things} in {sources}', [
                'things' => strtolower($this->label()),
                'sources' => implode(', ', $names) ?: '?',
            ]);
        }

        if ($target->status !== Target::STATUS_ANY && $target->status !== '') {
            $parts[] = Craft::t('nuke', 'with status “{status}”', ['status' => $target->status]);
        }

        if ($target->updatedBefore) {
            $parts[] = Craft::t('nuke', 'last updated before {date}', ['date' => $target->updatedBefore]);
        }

        if ($target->createdBefore) {
            $parts[] = Craft::t('nuke', 'created before {date}', ['date' => $target->createdBefore]);
        }

        if ($target->search) {
            $parts[] = Craft::t('nuke', 'matching “{search}”', ['search' => $target->search]);
        }

        if ($target->elementIds !== []) {
            $parts = [Craft::t('nuke', '{n, plural, =1{one specific element} other{# specific elements}}', [
                'n' => count($target->ids('elementIds')),
            ])];
        }

        $siteIds = $target->ids('siteIds');

        if ($siteIds !== []) {
            $names = [];

            foreach ($siteIds as $siteId) {
                $site = Craft::$app->getSites()->getSiteById($siteId);

                if ($site) {
                    $names[] = $site->name;
                }
            }

            $parts[] = Craft::t('nuke', 'on {sites}', ['sites' => implode(', ', $names)]);
        }

        if ($target->limit) {
            $parts[] = Craft::t('nuke', 'capped at {n}', ['n' => $target->limit]);
        }

        return ucfirst(implode(', ', $parts));
    }

    public function warnings(Target $target): array
    {
        return [];
    }

    public function hasStructure(Target $target): bool
    {
        return false;
    }

    public function validate(Target $target, Settings $settings): array
    {
        $errors = [];

        foreach ($this->sourceHandles($target) as $handle) {
            if ($settings->isProtected($handle)) {
                $errors[] = Craft::t('nuke', '“{handle}” is a protected scope and cannot be targeted.', [
                    'handle' => $handle,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Options in the shape the control panel's checkbox groups want, from anything with an id,
     * name and handle.
     *
     * @param iterable<object> $models
     * @return array<int, array{label: string, value: int, handle: string}>
     */
    protected function optionsFrom(iterable $models): array
    {
        $options = [];

        foreach ($models as $model) {
            $options[] = [
                'label' => (string)$model->name,
                'value' => (int)$model->id,
                'handle' => (string)$model->handle,
            ];
        }

        usort($options, fn(array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $options;
    }
}
