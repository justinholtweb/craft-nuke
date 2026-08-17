<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Db;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\models\SweepResult;

/**
 * Finds assets that nothing appears to reference.
 *
 * This is the one sweeper that cannot be certain, and it is built around that fact. Two sources
 * of truth are checked: the `relations` table, which is exact for anything in a relation field,
 * and reference tags in element content, which catches rich text and CKEditor. What neither
 * catches is an asset referenced only by a URL typed into raw HTML, hard-coded in a Twig
 * template, or pulled in by a third-party plugin that stores its own references.
 *
 * So the default is to report, not delete, and the strongest thing it will ever do is move an
 * asset to the trash — never remove the file. Emptying the trash is the `trashed` sweeper's job,
 * which means an asset this one gets wrong has a second, separately configured window in which
 * somebody can notice.
 */
class UnusedAssetsSweeper extends BaseSweeper
{
    public const MODE_REPORT = 'report';
    public const MODE_TRASH = 'trash';

    public static function handle(): string
    {
        return 'unusedAssets';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Unreferenced assets');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Finds assets with no relation and no reference tag in any element’s content. It cannot see assets referenced only by a URL in raw HTML or in a Twig template, so it reports by default and will never do more than move one to the trash.');
    }

    public function group(): string
    {
        return self::GROUP_CONTENT;
    }

    public function isAvailable(): bool
    {
        return Craft::$app->getVolumes()->getAllVolumes() !== [];
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'mode' => self::MODE_REPORT,
            'olderThanDays' => 180,
            'volumeIds' => [],
            'maxPerRun' => 500,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'mode',
                'label' => Craft::t('nuke', 'What to do with them'),
                'type' => 'select',
                'options' => [
                    ['label' => Craft::t('nuke', 'Report only'), 'value' => self::MODE_REPORT],
                    ['label' => Craft::t('nuke', 'Move to the trash'), 'value' => self::MODE_TRASH],
                ],
                'instructions' => Craft::t('nuke', 'Files are never deleted from the volume here. A trashed asset waits out the trash window first.'),
            ],
            [
                'name' => 'olderThanDays',
                'label' => Craft::t('nuke', 'Uploaded more than'),
                'type' => 'days',
                'instructions' => Craft::t('nuke', 'Gives an editor time to upload an asset and place it in a later session.'),
                'min' => 1,
                'max' => 3650,
            ],
            [
                'name' => 'maxPerRun',
                'label' => Craft::t('nuke', 'Most to act on per run'),
                'type' => 'number',
                'instructions' => Craft::t('nuke', 'A cap on how much one sweep can change, so a mistake is a small mistake.'),
                'min' => 1,
                'max' => 100000,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $cutoff = $this->cutoff($this->days($config));
        $mode = (string)($config['mode'] ?? self::MODE_REPORT);
        $max = max(1, (int)($config['maxPerRun'] ?? 500));

        $query = Asset::find()
            ->status(null)
            ->trashed(false)
            ->siteId('*')
            ->unique()
            ->limit(null);

        if ($volumeIds = array_filter(array_map('intval', (array)($config['volumeIds'] ?? [])))) {
            $query->volumeId($volumeIds);
        }

        if ($cutoff !== null) {
            $query->dateCreated('< ' . Db::prepareDateForDb($cutoff));
        }

        $candidates = array_map('intval', $query->ids());

        if ($candidates === []) {
            return;
        }

        $related = $this->relatedIds($candidates);
        $referenced = $this->referencedIds();

        $unused = array_values(array_filter(
            $candidates,
            fn(int $id) => !isset($related[$id]) && !isset($referenced[$id]),
        ));

        $result->found = count($unused);

        if ($result->found === 0) {
            return;
        }

        $unused = array_slice($unused, 0, $max);
        $result->bytes = $this->bytes($unused);

        foreach ($this->describe($unused) as $line) {
            $this->addSample($result, $line);
        }

        if ($result->found > count($unused)) {
            $result->message = Craft::t('nuke', 'Capped at {n} per run; {total} found in total.', [
                'n' => count($unused),
                'total' => $result->found,
            ]);
        }

        if ($dryRun || $mode !== self::MODE_TRASH) {
            if (!$dryRun && $mode === self::MODE_REPORT) {
                $result->message = trim(($result->message ?? '') . ' ' . Craft::t('nuke', 'Reporting only — nothing was moved.'));
            }

            return;
        }

        $elements = Craft::$app->getElements();

        foreach (array_chunk($unused, 100) as $chunk) {
            foreach (Asset::find()->id($chunk)->status(null)->siteId('*')->unique()->limit(null)->all() as $asset) {
                if ($elements->deleteElement($asset, false)) {
                    $result->removed++;
                }
            }
        }
    }

    /**
     * Candidate IDs that something relates to, as a lookup.
     *
     * @param int[] $candidates
     * @return array<int, true>
     */
    private function relatedIds(array $candidates): array
    {
        $found = [];

        foreach (array_chunk($candidates, 500) as $chunk) {
            $rows = (new Query())
                ->select(['targetId'])
                ->distinct()
                ->from(Table::RELATIONS)
                ->where(['targetId' => $chunk])
                ->column();

            foreach ($rows as $id) {
                $found[(int)$id] = true;
            }
        }

        return $found;
    }

    /**
     * Every asset ID mentioned by a reference tag anywhere in element content.
     *
     * One pass over the content column, rather than one query per candidate. Craft writes
     * reference tags as `{asset:123:url}` or, since Craft 4, `{asset:<uid>@<site>:url}`, so both
     * shapes are matched and UIDs resolved back to IDs in a single lookup at the end.
     *
     * @return array<int, true>
     */
    private function referencedIds(): array
    {
        $ids = [];
        $uids = [];

        $query = (new Query())
            ->select(['content'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['not', ['content' => null]])
            ->andWhere(['like', 'content', 'asset:']);

        foreach ($query->each(200) as $row) {
            $content = (string)($row['content'] ?? '');

            if ($content === '') {
                continue;
            }

            if (!preg_match_all('/asset:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\d+)/i', $content, $matches)) {
                continue;
            }

            foreach ($matches[1] as $match) {
                if (ctype_digit($match)) {
                    $ids[(int)$match] = true;
                } else {
                    $uids[strtolower($match)] = true;
                }
            }
        }

        if ($uids !== []) {
            foreach (array_chunk(array_keys($uids), 500) as $chunk) {
                $rows = (new Query())
                    ->select(['id'])
                    ->from(Table::ELEMENTS)
                    ->where(['uid' => $chunk])
                    ->column();

                foreach ($rows as $id) {
                    $ids[(int)$id] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * @param int[] $ids
     */
    private function bytes(array $ids): int
    {
        $bytes = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $bytes += (int)(new Query())
                ->from(Table::ASSETS)
                ->where(['id' => $chunk])
                ->sum('[[size]]');
        }

        return $bytes;
    }

    /**
     * @param int[] $ids
     * @return string[]
     */
    private function describe(array $ids): array
    {
        $rows = (new Query())
            ->select(['filename', 'size'])
            ->from(Table::ASSETS)
            ->where(['id' => array_slice($ids, 0, 10)])
            ->all();

        return array_map(
            fn(array $row) => sprintf('%s (%s)', $row['filename'], Bytes::format((int)$row['size'])),
            $rows,
        );
    }
}
