<?php

namespace justinholtweb\nuke\targets;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\models\Section;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\models\Target;

/**
 * Entries — the scope nearly every strike is aimed at.
 *
 * The one thing this does beyond the generic filters is warn about structures. Deleting a
 * structure entry deletes everything beneath it, and the sections that hold hierarchies are
 * exactly the ones where a target that looks small isn't.
 */
class EntryScope extends BaseScope
{
    public static function handle(): string
    {
        return 'entries';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Entries');
    }

    public function elementType(): string
    {
        return Entry::class;
    }

    public function sourceLabel(): string
    {
        return Craft::t('nuke', 'Sections');
    }

    public function sourceOptions(): array
    {
        return $this->optionsFrom(Craft::$app->getEntries()->getAllSections());
    }

    public function typeOptions(array $sourceIds = []): array
    {
        $entries = Craft::$app->getEntries();
        $sections = $sourceIds === []
            ? $entries->getAllSections()
            : array_filter(array_map(fn(int $id) => $entries->getSectionById($id), $sourceIds));

        $options = [];
        $seen = [];

        foreach ($sections as $section) {
            foreach ($section->getEntryTypes() as $type) {
                if (isset($seen[$type->id])) {
                    continue;
                }

                $seen[$type->id] = true;
                $options[] = [
                    'label' => $type->name,
                    'value' => (int)$type->id,
                    'handle' => $type->handle,
                ];
            }
        }

        usort($options, fn(array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    public function statusOptions(): array
    {
        return [
            ['label' => Craft::t('nuke', 'Any status'), 'value' => Target::STATUS_ANY],
            ['label' => Craft::t('nuke', 'Live'), 'value' => Entry::STATUS_LIVE],
            ['label' => Craft::t('nuke', 'Pending'), 'value' => Entry::STATUS_PENDING],
            ['label' => Craft::t('nuke', 'Expired'), 'value' => Entry::STATUS_EXPIRED],
            ['label' => Craft::t('nuke', 'Disabled'), 'value' => Element::STATUS_DISABLED],
        ];
    }

    protected function baseQuery(Target $target): ElementQuery
    {
        $query = Entry::find();

        if ($sectionIds = $target->ids('sourceIds')) {
            $query->sectionId($sectionIds);
        }

        if ($typeIds = $target->ids('typeIds')) {
            $query->typeId($typeIds);
        }

        return $query;
    }

    public function authorLabel(): ?string
    {
        return Craft::t('nuke', 'Authors');
    }

    /**
     * Any entry the users are *an* author of — co-authored entries included, and counted in the
     * warnings so the operator sees them.
     *
     * Written against `entries_authors` directly rather than through `authorId()`, which Craft
     * skips entirely on the Solo edition. There, the filter would vanish and the strike would
     * match every entry in the target.
     */
    protected function applyAuthors(ElementQuery $query, array $userIds): void
    {
        $query->andWhere(['exists', (new Query())
            ->from(['nuke_authors' => Table::ENTRIES_AUTHORS])
            ->where('[[nuke_authors.entryId]] = [[entries.id]]')
            ->andWhere(['nuke_authors.authorId' => $userIds]),
        ]);
    }

    public function warnings(Target $target): array
    {
        $warnings = parent::warnings($target);
        $authorIds = $target->ids('authorIds');

        if ($authorIds === [] || $target->elementIds !== []) {
            return $warnings;
        }

        // An entry with two authors is as much the other author's work, and deleting it takes
        // that with it — which is easy to forget when the target reads "by jsmith".
        $shared = (int)$this->query($target)
            ->andWhere(['exists', (new Query())
                ->from(['nuke_others' => Table::ENTRIES_AUTHORS])
                ->where('[[nuke_others.entryId]] = [[entries.id]]')
                ->andWhere(['not', ['nuke_others.authorId' => $authorIds]]),
            ])
            ->count();

        if ($shared > 0) {
            $warnings[] = Craft::t('nuke', '{n, plural, =1{One entry has} other{# entries have}} other authors too, and will be deleted along with everyone’s work on {n, plural, =1{it} other{them}}.', [
                'n' => $shared,
            ]);
        }

        return $warnings;
    }

    /**
     * Adds the entry types to the sentence, which the generic description has no way to know
     * about — and "entries in News" reading the same whether or not a type filter is set is
     * exactly the kind of quiet difference a preview exists to prevent.
     */
    public function describe(Target $target): string
    {
        $description = parent::describe($target);
        $typeIds = $target->ids('typeIds');

        if ($typeIds === [] || $target->elementIds !== []) {
            return $description;
        }

        $names = [];

        foreach ($this->typeOptions() as $option) {
            if (in_array($option['value'], $typeIds, true)) {
                $names[] = $option['label'];
            }
        }

        if ($names === []) {
            return $description;
        }

        return Craft::t('nuke', '{description}, of type {types}', [
            'description' => $description,
            'types' => implode(', ', $names),
        ]);
    }

    public function validate(Target $target, Settings $settings): array
    {
        $errors = parent::validate($target, $settings);

        // A single-entry section holds exactly one entry and Craft recreates it on save. Deleting
        // it looks like it worked and leaves the section in a state the control panel repairs
        // behind the operator's back, so the strike is refused rather than half-honoured.
        foreach ($this->targetedSections($target) as $section) {
            if ($section->type === Section::TYPE_SINGLE) {
                $errors[] = Craft::t('nuke', '“{name}” is a Single. Delete the section itself instead — Craft recreates the entry.', [
                    'name' => $section->name,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Whether any targeted section is a structure, which is what makes descendants possible.
     */
    public function hasStructure(Target $target): bool
    {
        foreach ($this->targetedSections($target) as $section) {
            if ($section->type === Section::TYPE_STRUCTURE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Section[]
     */
    private function targetedSections(Target $target): array
    {
        $entries = Craft::$app->getEntries();
        $ids = $target->ids('sourceIds');

        if ($ids === []) {
            return $entries->getAllSections();
        }

        return array_values(array_filter(array_map(fn(int $id) => $entries->getSectionById($id), $ids)));
    }
}
