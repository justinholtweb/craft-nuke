<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use justinholtweb\nuke\models\SweepResult;

/**
 * Clears out drafts nobody is coming back to.
 *
 * Two kinds pile up. **Unsaved drafts** are the rows Craft creates the moment someone clicks
 * "New entry" and then closes the tab. **Provisional drafts** are the autosave copy Craft keeps
 * while an entry is being edited — one per user per entry, recreated on the next edit, and
 * harmless to delete when the edit finished weeks ago.
 *
 * Named drafts that a person deliberately saved are never touched. Those are somebody's work in
 * progress, and a housekeeping task is the wrong thing to be making that judgement.
 */
class DraftsSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'drafts';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Abandoned drafts');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes unsaved drafts and stale autosave (provisional) drafts. Named drafts that someone saved on purpose are never touched.');
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
            'includeProvisional' => true,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'olderThanDays',
                'label' => Craft::t('nuke', 'Older than'),
                'type' => 'days',
                'instructions' => Craft::t('nuke', 'Measured from when the draft was last touched.'),
                'min' => 1,
                'max' => 3650,
            ],
            [
                'name' => 'includeProvisional',
                'label' => Craft::t('nuke', 'Include autosave drafts'),
                'type' => 'boolean',
                'instructions' => Craft::t('nuke', 'Craft recreates a provisional draft the next time the entry is edited, so removing an old one loses nothing.'),
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $cutoff = $this->cutoff($this->days($config));

        if ($cutoff === null) {
            $result->skip(Craft::t('nuke', 'No age window set.'));
            return;
        }

        // The age lives on the draft's *element* row, not on the `drafts` row — `drafts` has no
        // date columns at all. Deleting the drafts row is what cascades the element away, so the
        // query joins one to filter and deletes from the other.
        $condition = [
            'and',
            ['not', ['e.draftId' => null]],
            ['<', 'e.dateUpdated', Db::prepareDateForDb($cutoff)],
            $this->kindCondition($config),
        ];

        $ids = (new Query())
            ->select(['d.id'])
            ->from(['d' => Table::DRAFTS])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.draftId]] = [[d.id]]')
            ->where($condition)
            ->column();

        $ids = array_map('intval', $ids);
        $result->found = count($ids);

        if ($result->found > 0) {
            $this->addSample($result, Craft::t('nuke', '{n} draft rows, oldest last touched before {date}', [
                'n' => $result->found,
                'date' => $cutoff->format('Y-m-d'),
            ]));
        }

        if ($dryRun || $ids === []) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $result->removed += Db::delete(Table::DRAFTS, ['id' => $chunk]);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<mixed>
     */
    private function kindCondition(array $config): array
    {
        // `saved` is Craft's flag for "someone pressed Save on this draft". Unsaved ones are
        // always fair game; provisional ones only when asked for.
        if (!empty($config['includeProvisional'])) {
            return ['or', ['d.saved' => false], ['d.provisional' => true]];
        }

        return ['d.saved' => false];
    }
}
