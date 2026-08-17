<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use justinholtweb\nuke\models\SweepResult;

/**
 * Removes relation rows that point at something deleted.
 *
 * Two populations, and they are not equally safe to remove. **Dangling** rows point at an element
 * id that no longer exists at all — Craft cascades `relations.sourceId` but has no foreign key on
 * `targetId`, so anything hard-deleted outside Craft's own delete path leaves these behind. They
 * are wrong by definition and always go.
 *
 * **Trashed** rows point at an element that is soft-deleted and could still be restored. Those are
 * what a restore uses to put the relationship back, so removing them is opt-in and off by default.
 */
class OrphanedRelationsSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'orphanedRelations';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Relations to trashed elements');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes relation rows pointing at elements that no longer exist. With “include trashed” on it also removes relations to elements still in the trash — off by default, because while an element is recoverable its relations are part of what would be recovered.');
    }

    public function group(): string
    {
        return self::GROUP_DATABASE;
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'includeTrashed' => false,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'includeTrashed',
                'label' => Craft::t('nuke', 'Also remove relations to trashed elements'),
                'type' => 'boolean',
                'instructions' => Craft::t('nuke', 'Leaves those elements restorable but no longer related to anything.'),
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        // Dangling: no element row at either end. Always swept.
        $condition = ['or', ['not exists', $this->element('targetId')]];

        if (!empty($config['includeTrashed'])) {
            $condition[] = ['exists', $this->trashed('sourceId')];
            $condition[] = ['exists', $this->trashed('targetId')];
        }

        $result->found = (int)(new Query())
            ->from(Table::RELATIONS)
            ->where($condition)
            ->count();

        if ($dryRun || $result->found === 0) {
            return;
        }

        $result->removed = Db::delete(Table::RELATIONS, $condition);
    }

    /**
     * A correlated subquery matching any element row on one side of the relation.
     *
     * The correlation names the real table rather than an alias, because this ends up inside a
     * `DELETE`, where the outer table has no alias to refer to.
     */
    private function element(string $column): Query
    {
        return (new Query())
            ->from(['e' => Table::ELEMENTS])
            ->where("[[e.id]] = {{%relations}}.[[$column]]");
    }

    /**
     * The same, narrowed to elements that are in the trash.
     */
    private function trashed(string $column): Query
    {
        return $this->element($column)->andWhere(['not', ['e.dateDeleted' => null]]);
    }
}
