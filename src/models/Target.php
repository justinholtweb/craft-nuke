<?php

namespace justinholtweb\nuke\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * What a strike is aimed at.
 *
 * A target is pure configuration — it names a scope and narrows it, and knows nothing about how
 * to turn itself into an element query. That job belongs to the {@see \justinholtweb\nuke\targets\ScopeInterface}
 * implementation named by {@see $scope}, which keeps element-type-specific query building in one
 * place per element type instead of in one large switch.
 *
 * The same target object is used for the dry run and for the execution. That is the whole point:
 * a preview that builds its own query is a preview of a different deletion.
 */
class Target extends Model
{
    public const STATUS_ANY = 'any';

    // ---------------------------------------------------------------------
    // Scope
    // ---------------------------------------------------------------------

    /**
     * @var string Handle of the scope being targeted — `entries`, `assets`, `users`, and so on.
     */
    public string $scope = 'entries';

    /**
     * @var int[] IDs of the sources within that scope: section IDs for entries, volume IDs for
     *            assets, group IDs for categories, tags and users. Empty means every source,
     *            which is why the preview says so in words.
     */
    public array $sourceIds = [];

    /**
     * @var int[] Entry type IDs, narrowing an entries target further. Only meaningful for entries.
     */
    public array $typeIds = [];

    /**
     * @var int[] Site IDs. Empty means every site the elements exist in.
     */
    public array $siteIds = [];

    // ---------------------------------------------------------------------
    // Filters
    // ---------------------------------------------------------------------

    /**
     * @var string Element status to match, or `any`.
     *
     * Note that statuses are not universal: `live` is entry-only, and asking a category query for
     * it returns nothing at all rather than erroring. Each scope maps this itself.
     */
    public string $status = self::STATUS_ANY;

    /**
     * @var string|null Only match elements last updated before this. Accepts anything
     *                  `DateTimeHelper` understands, including relative strings like `-1 year`.
     */
    public ?string $updatedBefore = null;

    /**
     * @var string|null Only match elements created before this.
     */
    public ?string $createdBefore = null;

    /**
     * @var string|null Free-text search, run through Craft's search index.
     */
    public ?string $search = null;

    /**
     * @var int[] Explicit element IDs. When set, every other filter is ignored — this is the
     *            "delete exactly these" path used by the console and by re-running a saved run.
     */
    public array $elementIds = [];

    /**
     * @var int|null Stop after this many elements. Null means no limit beyond the settings ceiling.
     */
    public ?int $limit = null;

    // ---------------------------------------------------------------------
    // What travels with the elements
    // ---------------------------------------------------------------------

    /**
     * @var bool Whether to permanently remove the drafts and revisions of matched elements.
     *
     * Craft already takes them with the element either way — a hard delete cascades through the
     * `drafts` and `revisions` foreign keys, and a soft delete soft-deletes them alongside. This
     * flag is about the *soft* case: with it on, the history is removed outright while the
     * elements themselves stay in the trash.
     *
     * Off by default, because it is the one option here that makes a recoverable strike partly
     * unrecoverable: restore the entry afterwards and it comes back with nothing to revert to.
     */
    public bool $purgeHistory = false;

    /**
     * @var bool Whether elements already in the trash count as matches.
     *
     * Off by default: someone put them there on purpose and the trash has its own expiry.
     */
    public bool $includeTrashed = false;

    /**
     * @var bool Whether to remove rows in the `relations` table that point at, or out of, the
     *           deleted elements.
     *
     * Only meaningful for a soft delete. A hard delete clears them regardless — Craft's cascade
     * covers `relations.sourceId` but there is no foreign key on `targetId` at all, so anything
     * pointing *at* a permanently deleted element would otherwise be left dangling.
     *
     * Trashed elements already drop out of relation fields on the front end, so for a soft delete
     * this doesn't change what visitors see; what it changes is whether restoring the elements
     * restores the relationships. Off by default for that reason, and worth turning on when a
     * section is being decommissioned rather than tidied.
     */
    public bool $deleteRelations = false;

    // ---------------------------------------------------------------------
    // How it runs
    // ---------------------------------------------------------------------

    /**
     * @var bool Whether to bypass the trash and delete permanently.
     */
    public bool $hardDelete = false;

    /**
     * @var bool Whether to take a database backup first.
     */
    public bool $backup = true;

    /**
     * @var bool Whether to run Craft's garbage collector afterwards.
     */
    public bool $runGc = true;

    /**
     * @var bool Whether to run in the queue rather than in the request.
     */
    public bool $queue = false;

    /**
     * @var string|null Free-text note recorded against the run. "Decommissioning the 2019
     *                  microsite" is worth more in six months than any amount of inferred context.
     */
    public ?string $note = null;

    public function rules(): array
    {
        return [
            [['scope'], 'required'],
            [['limit'], 'integer', 'min' => 1],
            [['sourceIds', 'typeIds', 'siteIds', 'elementIds'], 'validateIdList'],
            [['updatedBefore', 'createdBefore'], 'validateDate'],
        ];
    }

    public function validateIdList(string $attribute): void
    {
        foreach ($this->$attribute as $id) {
            if (!is_numeric($id) || (int)$id <= 0) {
                $this->addError($attribute, "“{$id}” is not an ID.");
                return;
            }
        }
    }

    public function validateDate(string $attribute): void
    {
        if ($this->$attribute === null || trim($this->$attribute) === '') {
            return;
        }

        if ($this->dateFor($attribute) === null) {
            $this->addError($attribute, "“{$this->$attribute}” could not be read as a date.");
        }
    }

    /**
     * Reads one of the date attributes, accepting both absolute dates and relative strings.
     *
     * `DateTimeHelper::toDateTime()` handles the absolute forms and rejects `-90 days`, so
     * relative strings fall through to `DateTime` itself. Anything neither can parse is null,
     * and the caller treats that as "no filter" only after validation has already complained.
     */
    public function dateFor(string $attribute): ?DateTime
    {
        $value = trim((string)($this->$attribute ?? ''));

        if ($value === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value, false, false);

        if ($date instanceof DateTime) {
            return $date;
        }

        try {
            return new DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Normalises the ID arrays, which arrive from the control panel as strings and from the
     * console as comma-separated text.
     *
     * @return int[]
     */
    public function ids(string $attribute): array
    {
        return array_values(array_filter(array_map('intval', $this->$attribute)));
    }
}
