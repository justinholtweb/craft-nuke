<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use justinholtweb\nuke\models\SweepResult;

/**
 * Removes search index rows for elements that are gone.
 *
 * `searchindex` has no foreign key to `elements` — it can't, because it is rebuilt constantly and
 * a key would make every element save more expensive. Craft deletes an element's rows when it
 * hard-deletes the element, but rows left behind by a failed delete, a restored database, or a
 * `DELETE` run by hand have nothing to clean them up, and on a big site the index is one of the
 * largest tables there is.
 */
class SearchIndexSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'searchIndex';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Stale search index rows');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes search index rows whose element no longer exists or is in the trash. Nothing that still has an element is touched, so searching is unaffected.');
    }

    public function group(): string
    {
        return self::GROUP_DATABASE;
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $live = (new Query())
            ->from(['e' => Table::ELEMENTS])
            ->where('[[e.id]] = {{%searchindex}}.[[elementId]]')
            ->andWhere(['e.dateDeleted' => null]);

        $condition = ['not exists', $live];

        $result->found = (int)(new Query())
            ->from(Table::SEARCHINDEX)
            ->where($condition)
            ->count();

        if ($dryRun || $result->found === 0) {
            return;
        }

        $result->removed = Db::delete(Table::SEARCHINDEX, $condition);
    }
}
