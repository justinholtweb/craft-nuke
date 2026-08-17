<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use justinholtweb\nuke\models\SweepResult;
use justinholtweb\nuke\Plugin;

/**
 * Runs Craft's own garbage collector.
 *
 * Always last in a sweep. The other sweepers leave work behind for it — a deleted draft's element
 * row, a trashed element's extension-table rows, orphaned nested elements — and running it at the
 * end means one pass picks all of that up instead of leaving it until the collector's next
 * probabilistic firing, which on a low-traffic site may be days away.
 *
 * It is also the only part of the plugin that deletes things Nuke did not choose. That is the
 * point of it, and it is why it can be switched off.
 */
class GarbageCollectionSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'gc';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Craft garbage collection');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Runs Craft’s own garbage collector at the end of the sweep, so everything the other tasks orphaned is cleared in the same pass. Craft normally fires this at random on a small percentage of requests.');
    }

    public function group(): string
    {
        return self::GROUP_CRAFT;
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'deleteAllTrashed' => false,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'deleteAllTrashed',
                'label' => Craft::t('nuke', 'Empty the trash completely'),
                'type' => 'boolean',
                'instructions' => Craft::t('nuke', 'Ignores Craft’s softDeleteDuration and hard-deletes everything in the trash, however recently it went there. Leave off unless you mean it.'),
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        if ($dryRun) {
            $result->ran = true;
            $result->message = Craft::t('nuke', 'Would run Craft’s garbage collector.');
            return;
        }

        $gc = Craft::$app->getGc();
        $emptyTrash = !empty($config['deleteAllTrashed']);

        // The collector writes a line per table it touches. That is right for
        // `craft nuke/sweep/run` and wrong everywhere else, including a queue worker — which is
        // a console request too, so the request type can't be the test. Both flags are restored
        // afterwards, since they are application-wide and a sweep should not change how the rest
        // of the process behaves.
        $wasSilent = $gc->silent;
        $wasDeleteAll = $gc->deleteAllTrashed;

        $gc->silent = !Plugin::getInstance()->sweep->verbose;
        $gc->deleteAllTrashed = $emptyTrash;

        try {
            $gc->run(true);
        } finally {
            $gc->silent = $wasSilent;
            $gc->deleteAllTrashed = $wasDeleteAll;
        }

        $result->ran = true;
        $result->message = $emptyTrash
            ? Craft::t('nuke', 'Ran, emptying the trash completely.')
            : Craft::t('nuke', 'Ran.');
    }
}
