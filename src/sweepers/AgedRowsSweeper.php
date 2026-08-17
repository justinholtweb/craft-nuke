<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\helpers\Db;
use justinholtweb\nuke\models\SweepResult;

/**
 * Base for the sweepers whose whole job is "delete rows in this table older than N days".
 *
 * There are ten of those. Written out longhand they would be ten copies of the same six lines,
 * which is ten places for the dry run and the delete to use subtly different conditions.
 * Subclasses name their tables and, if they need one, an extra condition.
 */
abstract class AgedRowsSweeper extends BaseSweeper
{
    /**
     * Tables to sweep, mapped to the column holding the row's age.
     *
     * @return array<string, string>
     */
    abstract protected function tables(): array;

    /**
     * An extra condition for one table, beyond the age filter.
     *
     * @return array<mixed>|null
     */
    protected function condition(string $table): ?array
    {
        return null;
    }

    public function group(): string
    {
        return self::GROUP_DATABASE;
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'olderThanDays' => 90,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'olderThanDays',
                'label' => Craft::t('nuke', 'Older than'),
                'type' => 'days',
                'instructions' => Craft::t('nuke', '0 removes every row, whatever its age.'),
                'min' => 0,
                'max' => 3650,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $cutoff = $this->cutoff($this->days($config));

        foreach ($this->tables() as $table => $dateColumn) {
            $condition = ['and'];

            if ($cutoff !== null) {
                $condition[] = ['<', $dateColumn, Db::prepareDateForDb($cutoff)];
            }

            if ($extra = $this->condition($table)) {
                $condition[] = $extra;
            }

            // `['and']` on its own is an empty condition, which Yii renders as no WHERE clause at
            // all — correct here, since "no age filter and no extra condition" really does mean
            // every row.
            $before = $result->found;
            $this->sweepRows($result, $table, $condition, $dryRun);

            if ($result->found > $before) {
                $this->addSample($result, sprintf('%s × %d', $this->shortTableName($table), $result->found - $before));
            }
        }
    }

    private function shortTableName(string $table): string
    {
        return trim(str_replace(['{{%', '}}'], '', $table));
    }
}
