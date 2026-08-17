<?php

namespace justinholtweb\nuke\sweepers;

use justinholtweb\nuke\models\SweepResult;

/**
 * One housekeeping task.
 *
 * A sweeper has to be able to answer "what would you remove?" as cheaply and as accurately as it
 * answers "remove it". Both questions go through the same method with a flag, so a sweeper cannot
 * report a preview its execution doesn't match — see {@see BaseSweeper::run()}.
 */
interface SweeperInterface
{
    /** Sweepers that clean up content: trash, drafts, revisions, unreferenced assets. */
    public const GROUP_CONTENT = 'content';

    /** Sweepers that clean up database rows nothing points at any more. */
    public const GROUP_DATABASE = 'database';

    /** Sweepers that clean up files under `storage/`. */
    public const GROUP_FILES = 'files';

    /** Craft's own maintenance. */
    public const GROUP_CRAFT = 'craft';

    /**
     * Stable identifier, used in settings, console arguments and run records.
     */
    public static function handle(): string;

    public function label(): string;

    /**
     * One or two sentences: what it removes, and what it deliberately leaves alone. This is what
     * an operator reads before switching it on, so it says the second part too.
     */
    public function description(): string;

    /**
     * One of the GROUP_* constants.
     */
    public function group(): string;

    /**
     * Whether this sweeper can run here at all — the table exists, the driver supports it, the
     * directory is present.
     */
    public function isAvailable(): bool;

    /**
     * Defaults for every key this sweeper reads from its config, including `enabled`.
     *
     * A sweeper added in a later release brings its own default with it, so upgrading a site
     * doesn't leave it silently switched off just because it wasn't in a list written before it
     * existed.
     *
     * @return array<string, mixed>
     */
    public function defaultConfig(): array;

    /**
     * Field definitions for the settings screen, in the order they should appear.
     *
     * @return array<int, array{name: string, label: string, type: string, instructions?: string, min?: int, max?: int}>
     */
    public function configFields(): array;

    /**
     * What this sweeper would remove.
     *
     * @param array<string, mixed> $config
     */
    public function scan(array $config): SweepResult;

    /**
     * Remove it.
     *
     * @param array<string, mixed> $config
     */
    public function sweep(array $config): SweepResult;
}
