<?php

namespace justinholtweb\nuke\targets;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\models\Target;
use LogicException;
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

    /**
     * What "authored by" is called for this element type, or null if it has no such idea.
     *
     * Null is the default and it is a refusal, not a no-op: a target that names authors on a
     * scope that can't filter by them is turned away by the detonator, since quietly dropping the
     * filter would aim the strike at everyone's content.
     */
    public function authorLabel(): ?string
    {
        return null;
    }

    /**
     * Narrows the query to elements the given users authored. Scopes that return a label from
     * {@see authorLabel()} must override this.
     *
     * @param int[] $userIds
     */
    protected function applyAuthors(ElementQuery $query, array $userIds): void
    {
        throw new LogicException(static::class . ' has an author label but does not apply authors.');
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

        if ($authorIds = $target->ids('authorIds')) {
            $this->applyAuthors($query, $authorIds);
        }

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

        if ($target->ids('authorIds') !== []) {
            $parts[] = Craft::t('nuke', 'by {users}', [
                'users' => implode(', ', array_map(
                    fn(User $user) => $user->trashed
                        ? Craft::t('nuke', '{username} (removed)', ['username' => $user->username])
                        : $user->username,
                    $this->authors($target),
                )) ?: '?',
            ]);
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
        $warnings = [];

        if ($target->elementIds !== []) {
            return $warnings;
        }

        $softDeleteDuration = (int)Craft::$app->getConfig()->getGeneral()->softDeleteDuration;

        foreach ($this->authors($target) as $user) {
            if (!$user->trashed) {
                continue;
            }

            // The window matters because it closes without anyone doing anything: garbage
            // collection deletes the account, the authorship rows cascade with it, and whatever
            // this strike didn't catch is left with no author to find it by.
            $warnings[] = $softDeleteDuration > 0 && $user->dateDeleted
                ? Craft::t('nuke', '“{username}” was removed on {date}. Craft deletes the account for good after {purge}, and their content can’t be matched by author after that.', [
                    'username' => $user->username,
                    'date' => Craft::$app->getFormatter()->asDate($user->dateDeleted, 'short'),
                    'purge' => Craft::$app->getFormatter()->asDate($user->dateDeleted->getTimestamp() + $softDeleteDuration, 'short'),
                ])
                : Craft::t('nuke', '“{username}” has been removed, and is still in Craft’s trash.', [
                    'username' => $user->username,
                ]);
        }

        return $warnings;
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

        $authorIds = $target->ids('authorIds');

        if ($authorIds !== [] && $target->elementIds === []) {
            $found = array_map(fn(User $user) => (int)$user->id, $this->authors($target));

            foreach (array_diff($authorIds, $found) as $missingId) {
                // A permanently deleted user's authorship rows went with the account, so there is
                // nothing left to match on. Matching nothing would be harmless; saying why is kinder.
                $errors[] = Craft::t('nuke', 'User #{id} no longer exists. Once an account is permanently deleted, Craft no longer records what it authored.', [
                    'id' => $missingId,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Craft's own permissions a user needs to delete everything in one source — all of it, not
     * just their own, because a strike takes everyone's. `[]` when Craft has none (tags), null when
     * the source doesn't exist.
     *
     * @return string[]|null
     */
    public function deletePermissions(int $sourceId): ?array
    {
        return null;
    }

    /**
     * The column holding an element's source, for working out which sources an explicit list of
     * element IDs reaches. Null when the scope has no sources to check.
     */
    protected function sourceColumn(): ?string
    {
        return null;
    }

    /**
     * Why this user may not fire this target, in Craft's own permission terms. Empty when they may.
     *
     * Nuke's own permission says someone may use Nuke; it says nothing about which content they may
     * delete. Without this, "Strike" granted to clean up one section reached every section and
     * volume on the site, including ones the user can't even see.
     *
     * @return string[]
     */
    public function permissionErrors(Target $target, User $user): array
    {
        if ($user->admin) {
            return [];
        }

        if ($target->elementIds !== []) {
            // An explicit list ignores the source filter, so check where the elements really are.
            $column = $this->sourceColumn();

            if ($column === null) {
                return [Craft::t('nuke', 'Only an admin can delete specific {things} by ID.', ['things' => strtolower($this->label())])];
            }

            $sourceIds = $this->query($target)->select([$column])->distinct()->column();

            if (in_array(null, $sourceIds, true)) {
                return [Craft::t('nuke', 'Some of these {things} don’t belong to any {source}, so only an admin can delete them.', [
                    'things' => strtolower($this->label()),
                    'source' => strtolower($this->sourceLabel()),
                ])];
            }
        } else {
            $sourceIds = $target->ids('sourceIds');

            if ($sourceIds === []) {
                // "Everything" reaches content no source permission covers — nested entries in
                // Matrix fields have no section at all — so only an admin may aim that wide.
                return [Craft::t('nuke', 'Choose the {sources} to delete from. Only an admin can target all of them at once.', [
                    'sources' => strtolower($this->sourceLabel()),
                ])];
            }
        }

        $denied = [];

        foreach (array_unique(array_map('intval', $sourceIds)) as $sourceId) {
            $permissions = $this->deletePermissions($sourceId);

            if ($permissions === null) {
                $denied[] = '#' . $sourceId;
                continue;
            }

            foreach ($permissions as $permission) {
                if (!$user->can($permission)) {
                    $denied[] = $this->sourceName($sourceId);
                    break;
                }
            }
        }

        if ($denied === []) {
            return [];
        }

        return [Craft::t('nuke', 'You don’t have permission to delete everything in {sources}.', [
            'sources' => implode(', ', $denied),
        ])];
    }

    /**
     * The sources this user may target in full — what the source picker offers.
     *
     * @return array<int, array{label: string, value: int, handle: string}>
     */
    public function sourceOptionsFor(User $user): array
    {
        if ($user->admin) {
            return $this->sourceOptions();
        }

        return array_values(array_filter($this->sourceOptions(), function(array $option) use ($user) {
            $permissions = $this->deletePermissions((int)$option['value']);

            if ($permissions === null) {
                return false;
            }

            foreach ($permissions as $permission) {
                if (!$user->can($permission)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function sourceName(int $sourceId): string
    {
        foreach ($this->sourceOptions() as $option) {
            if ((int)$option['value'] === $sourceId) {
                return $option['label'];
            }
        }

        return '#' . $sourceId;
    }

    /**
     * The users a target names as authors, including ones sitting in the trash.
     *
     * @return User[]
     */
    protected function authors(Target $target): array
    {
        $ids = $target->ids('authorIds');

        if ($ids === []) {
            return [];
        }

        return User::find()
            ->id($ids)
            ->status(null)
            ->trashed(null)
            ->limit(null)
            ->all();
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
