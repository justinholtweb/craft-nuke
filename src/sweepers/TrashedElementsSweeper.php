<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use justinholtweb\nuke\models\SweepResult;

/**
 * Empties the trash on your own schedule instead of Craft's.
 *
 * Craft's garbage collector already hard-deletes trashed elements once `softDeleteDuration` has
 * passed — a single, global config value that a lot of sites never change from 30 days. This
 * sweeper does the same job with its own window, and, more usefully, *reports* what is sitting in
 * the trash before it goes.
 *
 * Nested elements (Matrix entries and the like) are counted but left alone. Craft only hard-
 * deletes those once it has checked that no surviving revision still needs them, and that check
 * is subtle enough that duplicating it here would be a good way to corrupt revision history.
 * They are handed to the `gc` sweeper instead.
 */
class TrashedElementsSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'trashed';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Trashed elements');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Permanently deletes elements that have been in the trash longer than the window below. Nested elements such as Matrix entries are counted but left for Craft’s own collector, which knows which ones a revision still depends on.');
    }

    public function group(): string
    {
        return self::GROUP_CONTENT;
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'olderThanDays' => 30,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'olderThanDays',
                'label' => Craft::t('nuke', 'Older than'),
                'type' => 'days',
                'instructions' => Craft::t('nuke', 'How long something stays recoverable in the trash. 0 empties it completely.'),
                'min' => 0,
                'max' => 3650,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $cutoff = $this->cutoff($this->days($config));

        $condition = ['and', ['not', ['dateDeleted' => null]]];

        if ($cutoff !== null) {
            $condition[] = ['<', 'dateDeleted', Db::prepareDateForDb($cutoff)];
        }

        [$simple, $nested] = $this->elementTypes();

        $counts = (new Query())
            ->select(['type', 'n' => 'COUNT(*)'])
            ->from(Table::ELEMENTS)
            ->where($condition)
            ->groupBy(['type'])
            ->orderBy(['n' => SORT_DESC])
            ->all();

        $nestedCount = 0;

        foreach ($counts as $row) {
            $type = (string)$row['type'];
            $n = (int)$row['n'];

            if (in_array($type, $nested, true)) {
                $nestedCount += $n;
                continue;
            }

            $result->found += $n;
            $this->addSample($result, sprintf('%s × %d', $this->displayName($type), $n));
        }

        if ($nestedCount > 0) {
            $result->message = Craft::t('nuke', '{n} nested elements left for Craft’s garbage collector.', ['n' => $nestedCount]);
        }

        if ($dryRun || $result->found === 0 || $simple === []) {
            return;
        }

        $result->removed = Db::delete(Table::ELEMENTS, ['and', $condition, ['type' => $simple]]);
    }

    /**
     * Element types split into the ones that can be deleted outright and the ones that can't.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function elementTypes(): array
    {
        $simple = [];
        $nested = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
            if (is_subclass_of($elementType, NestedElementInterface::class)) {
                $nested[] = $elementType;
            } else {
                $simple[] = $elementType;
            }
        }

        return [$simple, $nested];
    }

    private function displayName(string $elementType): string
    {
        return class_exists($elementType) ? $elementType::pluralDisplayName() : $elementType;
    }
}
