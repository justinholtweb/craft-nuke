<?php

namespace justinholtweb\nuke\models;

use yii\base\BaseObject;

/**
 * The dry run: everything a strike would do, counted, before it does any of it.
 *
 * A blast radius is produced by the same code path that later executes the strike, from the same
 * {@see Target}. Nothing in here is estimated — the element count is the count of the query that
 * will be iterated, and the relation counts are the rows that will be deleted.
 *
 * "Collateral" is the interesting part and the reason the preview exists at all: elements that
 * are *not* being deleted but hold a relation to something that is. Those are the fields that
 * silently empty out.
 *
 * Deliberately a `BaseObject` rather than a Craft `Model`: this carries a public `$errors` array
 * and a method that appends to it, and `Model` already owns both of those names for validation.
 * A subclass that redeclares `addError()` is a fatal compile error, and one that shadows
 * `getErrors()` with a property is worse — it works, until something reads the wrong one.
 */
class BlastRadius extends BaseObject
{
    /** @var int Canonical elements matched by the target. */
    public int $elements = 0;

    /** @var int Drafts belonging to those elements. */
    public int $drafts = 0;

    /** @var int Revisions belonging to those elements. */
    public int $revisions = 0;

    /**
     * @var int Structure descendants that survive but get re-parented.
     *
     * Craft does *not* delete the children of a structure element. `Elements::deleteElement()`
     * moves each child up to sit before the element being removed, then deletes the now-childless
     * node — so a target that takes out a top-level page leaves its whole subtree promoted to the
     * top level. That is almost never what the operator pictured, and it is invisible until
     * afterwards, so the preview counts it.
     */
    public int $promotedDescendants = 0;

    /** @var int Rows in `relations` pointing *at* the doomed elements. */
    public int $incomingRelations = 0;

    /** @var int Rows in `relations` pointing *out of* the doomed elements. */
    public int $outgoingRelations = 0;

    /** @var int Files on disk, for asset targets. */
    public int $files = 0;

    /** @var int Total bytes of those files. */
    public int $bytes = 0;

    /**
     * @var array<int, array{id: int, title: string, status: string|null, url: string|null}>
     *      A sample of what matched, for the operator to sanity-check. Not the whole set — the
     *      whole set is the number above it.
     */
    public array $sample = [];

    /**
     * @var array<int, array{id: int, title: string, type: string, url: string|null, count: int}>
     *      Elements that survive the strike but reference something in it.
     */
    public array $collateral = [];

    /** @var int Total distinct surviving elements referencing the doomed set. */
    public int $collateralCount = 0;

    /** @var string[] Reasons the strike cannot run at all. */
    public array $errors = [];

    /** @var string[] Things worth reading before running it. */
    public array $warnings = [];

    /** @var string A human sentence describing the target, e.g. "every entry in News, on all sites". */
    public string $description = '';

    /** @var bool Whether the element count hit the configured ceiling. */
    public bool $overLimit = false;

    /** @var float Seconds spent computing the preview. */
    public float $duration = 0.0;

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    public function canFire(): bool
    {
        return $this->errors === [] && !$this->isEmpty();
    }

    /**
     * Everything that would be removed, as one number.
     *
     * Relations are excluded deliberately — they are rows, not content, and adding them makes a
     * modest deletion look enormous. Promoted descendants are excluded because they survive.
     */
    public function total(): int
    {
        return $this->elements + $this->drafts + $this->revisions;
    }

    public function refuse(string $message): void
    {
        $this->errors[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
