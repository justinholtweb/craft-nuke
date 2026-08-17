<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use justinholtweb\nuke\models\SweepResult;

/**
 * Trims revision history back to the newest few per element.
 *
 * Craft prunes revisions when it *saves* an element, down to `maxRevisions`. Lower that setting
 * on an established site and nothing happens to the history already there — it only takes effect
 * the next time each element is saved, which for an archive of 40,000 entries is never. Revisions
 * are also full element snapshots, so they are usually the single largest thing in the database.
 *
 * This sweeper applies the limit retroactively.
 */
class RevisionsSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'revisions';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Excess revisions');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Keeps the newest revisions of each element and deletes the rest. Craft only applies its own maxRevisions limit when an element is saved, so lowering that setting never catches up with content nobody edits.');
    }

    public function group(): string
    {
        return self::GROUP_CONTENT;
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'keepPerElement' => (int)(Craft::$app->getConfig()->getGeneral()->maxRevisions ?: 10),
            'olderThanDays' => 0,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'keepPerElement',
                'label' => Craft::t('nuke', 'Revisions to keep per element'),
                'type' => 'number',
                'instructions' => Craft::t('nuke', 'Defaults to Craft’s own maxRevisions setting. The newest are kept.'),
                'min' => 1,
                'max' => 1000,
            ],
            [
                'name' => 'olderThanDays',
                'label' => Craft::t('nuke', 'And older than'),
                'type' => 'days',
                'instructions' => Craft::t('nuke', 'Optional second condition — a revision has to be both surplus and this old to go. 0 applies the count limit alone.'),
                'min' => 0,
                'max' => 3650,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $keep = max(1, (int)($config['keepPerElement'] ?? 10));
        $cutoff = $this->cutoff($this->days($config));

        // `revisions.num` counts up, so the newest `$keep` for an element are the ones at or above
        // `max(num) - $keep`. Working it out per element in SQL avoids pulling every revision row
        // into PHP on a site that has hundreds of thousands of them.
        $ceilings = (new Query())
            ->select(['canonicalId', 'top' => 'MAX([[num]])'])
            ->from(Table::REVISIONS)
            ->groupBy(['canonicalId'])
            ->having(['>', 'COUNT(*)', $keep])
            ->all();

        if ($ceilings === []) {
            return;
        }

        $doomed = [];

        foreach (array_chunk($ceilings, 200) as $chunk) {
            $conditions = ['or'];

            foreach ($chunk as $row) {
                $conditions[] = [
                    'and',
                    ['canonicalId' => (int)$row['canonicalId']],
                    ['<=', 'num', (int)$row['top'] - $keep],
                ];
            }

            $query = (new Query())
                ->select(['r.id'])
                ->from(['r' => Table::REVISIONS])
                ->where($conditions);

            if ($cutoff !== null) {
                $query
                    ->innerJoin(['e' => Table::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
                    ->andWhere(['<', 'e.dateCreated', Db::prepareDateForDb($cutoff)]);
            }

            foreach ($query->column() as $id) {
                $doomed[] = (int)$id;
            }
        }

        $result->found = count($doomed);

        if ($result->found > 0) {
            $this->addSample($result, Craft::t('nuke', '{n} revisions across {m} elements, keeping the newest {keep} of each', [
                'n' => $result->found,
                'm' => count($ceilings),
                'keep' => $keep,
            ]));
        }

        if ($dryRun || $doomed === []) {
            return;
        }

        foreach (array_chunk($doomed, 500) as $chunk) {
            $result->removed += Db::delete(Table::REVISIONS, ['id' => $chunk]);
        }
    }
}
