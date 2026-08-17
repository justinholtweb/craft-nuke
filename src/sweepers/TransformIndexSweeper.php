<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use justinholtweb\nuke\models\SweepResult;

/**
 * Removes image transform index rows whose asset is gone.
 *
 * Only orphans. Deleting a live transform's row would make Craft regenerate the image on the next
 * request while the old file stayed on the volume — trading a row for an unreferenced file, which
 * is not a trade worth making automatically.
 */
class TransformIndexSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'transformIndex';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Orphaned transform records');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes image transform index rows whose asset no longer exists. Rows for live assets are left alone — removing those would just make Craft regenerate the image.');
    }

    public function group(): string
    {
        return self::GROUP_DATABASE;
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $asset = (new Query())
            ->from(['e' => Table::ELEMENTS])
            ->where('[[e.id]] = {{%imagetransformindex}}.[[assetId]]')
            ->andWhere(['e.dateDeleted' => null]);

        $condition = ['not exists', $asset];

        $result->found = (int)(new Query())
            ->from(Table::IMAGETRANSFORMINDEX)
            ->where($condition)
            ->count();

        if ($dryRun || $result->found === 0) {
            return;
        }

        $result->removed = Db::delete(Table::IMAGETRANSFORMINDEX, $condition);
    }
}
