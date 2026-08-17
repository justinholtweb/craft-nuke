<?php

namespace justinholtweb\nuke\models;

use craft\base\Model;

/**
 * One sweeper's contribution to a sweep, in either mode.
 *
 * Scanning and sweeping return the same shape on purpose. A sweeper's scan sets {@see $found}
 * and leaves {@see $removed} at zero; a real sweep sets both. Anything that reads a sweep report
 * therefore works identically for a preview and for a run, and a sweeper cannot report a preview
 * that its execution doesn't match without the discrepancy being visible.
 */
class SweepResult extends Model
{
    public string $handle = '';
    public string $label = '';
    public string $group = '';

    /** @var int How many things the sweeper found to remove. */
    public int $found = 0;

    /** @var int How many it removed. Zero in a scan. */
    public int $removed = 0;

    /** @var int Bytes reclaimed, where the sweeper works on files. */
    public int $bytes = 0;

    /** @var bool Whether this was a scan rather than a sweep. */
    public bool $dryRun = false;

    /** @var bool Whether the sweeper declined to run — unsupported driver, missing directory, disabled. */
    public bool $skipped = false;

    /** @var string|null Why it skipped, or why it failed. */
    public ?string $message = null;

    /** @var bool Whether it threw. */
    public bool $failed = false;

    /**
     * @var string[] A sample of what was found, for the report. Filenames, table names, element
     *               titles — whatever makes the number checkable.
     */
    public array $sample = [];

    /** @var float Seconds spent. */
    public float $duration = 0.0;

    /**
     * @var bool Set by sweepers whose work isn't measured in things removed — running Craft's
     *           garbage collector, say. Without it, a sweeper that did real work would drop off
     *           a report that lists only what changed.
     */
    public bool $ran = false;

    public function found(int $n): static
    {
        $this->found = $n;
        return $this;
    }

    public function skip(string $why): static
    {
        $this->skipped = true;
        $this->message = $why;
        return $this;
    }

    /**
     * True when the sweeper has something to say on a report that lists only what matters.
     */
    public function isNoteworthy(): bool
    {
        return $this->failed || $this->ran || ($this->dryRun ? $this->found > 0 : $this->removed > 0);
    }
}
