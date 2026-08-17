<?php

namespace justinholtweb\nuke\targets;

use craft\elements\db\ElementQuery;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\models\Target;

/**
 * A kind of content a strike can be aimed at.
 *
 * One implementation per element type. Element types differ in ways that matter to a deletion —
 * which statuses they have, what "source" means, whether they can be nested, whether deleting one
 * takes others with it — and a single query builder handling all of them is a switch statement
 * that grows a new arm every time Craft gains an element type. Third-party element types can
 * register their own via {@see \justinholtweb\nuke\services\Scopes::EVENT_REGISTER_SCOPES}.
 */
interface ScopeInterface
{
    /**
     * Stable identifier, stored on runs and used in console arguments.
     */
    public static function handle(): string;

    /**
     * Label shown in the control panel.
     */
    public function label(): string;

    /**
     * The element class this scope deletes.
     *
     * @return class-string<\craft\base\ElementInterface>
     */
    public function elementType(): string;

    /**
     * Whether the scope can be offered at all — the element type exists, and the site has at
     * least one source of it. A site with no category groups shouldn't be offered categories.
     */
    public function isAvailable(): bool;

    /**
     * What a "source" is called here: Sections, Volumes, Category Groups, User Groups.
     */
    public function sourceLabel(): string;

    /**
     * The sources available to target.
     *
     * @return array<int, array{label: string, value: int, handle: string}>
     */
    public function sourceOptions(): array;

    /**
     * Entry types, or an empty array for scopes that have no second axis.
     *
     * @param int[] $sourceIds
     * @return array<int, array{label: string, value: int, handle: string}>
     */
    public function typeOptions(array $sourceIds = []): array;

    /**
     * Statuses this element type actually has.
     *
     * Asking an element query for a status its type does not support returns *nothing*, silently,
     * which looks exactly like "no matches" — so the options offered have to be per-type.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function statusOptions(): array;

    /**
     * A query matching the canonical elements the target names.
     *
     * Drafts and revisions are excluded here — they are counted and deleted separately, because
     * "delete the entries and their drafts" and "delete some drafts" are different operations
     * with different previews.
     */
    public function query(Target $target): ElementQuery;

    /**
     * The handles of the sources this target names, for the protected-scope check and for the
     * confirmation phrase.
     *
     * @return string[]
     */
    public function sourceHandles(Target $target): array;

    /**
     * A sentence describing what the target matches, for the preview and the run ledger.
     */
    public function describe(Target $target): string;

    /**
     * Reasons this target must not run. An empty array means the scope is content for it to.
     *
     * @return string[]
     */
    public function validate(Target $target, Settings $settings): array;

    /**
     * Things the operator should read before firing, which are not reasons to refuse.
     *
     * @return string[]
     */
    public function warnings(Target $target): array;

    /**
     * Whether elements in this target can have descendants that would be deleted with them.
     *
     * Used to decide whether the preview needs to spend a query counting them; scopes that can
     * never be hierarchical say no and save it.
     */
    public function hasStructure(Target $target): bool;
}
