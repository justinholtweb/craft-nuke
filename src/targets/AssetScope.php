<?php

namespace justinholtweb\nuke\targets;

use Craft;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use justinholtweb\nuke\models\Target;

/**
 * Assets — the one scope where deleting reaches outside the database.
 *
 * A soft delete leaves the file on the volume; a hard delete removes it. That asymmetry is worth
 * being explicit about, because "it's in the trash, I can get it back" is true for an entry and
 * only conditionally true for an asset.
 */
class AssetScope extends BaseScope
{
    public static function handle(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Assets');
    }

    public function elementType(): string
    {
        return Asset::class;
    }

    public function sourceLabel(): string
    {
        return Craft::t('nuke', 'Volumes');
    }

    public function sourceOptions(): array
    {
        return $this->optionsFrom(Craft::$app->getVolumes()->getAllVolumes());
    }

    protected function baseQuery(Target $target): ElementQuery
    {
        $query = Asset::find();
        $volumeIds = $target->ids('sourceIds');

        if ($volumeIds === []) {
            // Craft parks in-progress uploads in a volume-less folder. Naming every real volume,
            // rather than leaving the filter off, keeps a strike aimed at "all assets" away from
            // files someone is uploading right now.
            $volumeIds = array_map(
                static fn($volume) => (int)$volume->id,
                Craft::$app->getVolumes()->getAllVolumes(),
            );

            if ($volumeIds === []) {
                return $query->id(false);
            }
        }

        return $query->volumeId($volumeIds);
    }

    public function warnings(Target $target): array
    {
        $warnings = [];

        if ($target->hardDelete) {
            $warnings[] = Craft::t('nuke', 'Files will be deleted from their volumes, not just from Craft. Remote volumes have no trash.');
        } else {
            $warnings[] = Craft::t('nuke', 'Files stay on their volumes until Craft’s trash expires. Disk space is not reclaimed yet.');
        }

        return $warnings;
    }
}
