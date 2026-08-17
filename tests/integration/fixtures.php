<?php
/**
 * Builds throwaway content for exercising a strike against, and tears it down again.
 *
 *   php tests/integration/fixtures.php up
 *   php tests/integration/fixtures.php down
 *
 * Run from the Craft installation, not from the plugin:
 *   ddev exec php /var/www/craft-nuke/tests/integration/fixtures.php up
 */

// The Craft installation, not the plugin. Overridable so this works against any harness.
$base = getenv('CRAFT_BASE_PATH') ?: '/var/www/html';

require $base . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;

$mode = $argv[1] ?? 'up';
$entries = Craft::$app->getEntries();

function section(): ?Section
{
    return Craft::$app->getEntries()->getSectionByHandle('nukeFixture');
}

if ($mode === 'down') {
    $section = section();

    if ($section) {
        Craft::$app->getEntries()->deleteSection($section);
        echo "Deleted the fixture section.\n";
    }

    foreach (['nukeDoomed', 'nukeSurvivor'] as $handle) {
        $type = Craft::$app->getEntries()->getEntryTypeByHandle($handle);

        if ($type) {
            Craft::$app->getEntries()->deleteEntryType($type);
        }
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    echo "Done.\n";
    exit(0);
}

// --- entry types -----------------------------------------------------------
$types = [];

foreach (['nukeDoomed' => 'Nuke Doomed', 'nukeSurvivor' => 'Nuke Survivor'] as $handle => $name) {
    $type = $entries->getEntryTypeByHandle($handle);

    if (!$type) {
        $type = new EntryType();
        $type->name = $name;
        $type->handle = $handle;
        $entries->saveEntryType($type);
    }

    $types[$handle] = $type;
}

// --- section ---------------------------------------------------------------
$section = section();

if (!$section) {
    $section = new Section();
    $section->name = 'Nuke Fixture';
    $section->handle = 'nukeFixture';
    $section->type = Section::TYPE_STRUCTURE;

    $siteSettings = [];

    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        $settings = new Section_SiteSettings();
        $settings->siteId = $site->id;
        $settings->hasUrls = false;
        $siteSettings[$site->id] = $settings;
    }

    $section->setSiteSettings($siteSettings);
    $section->setEntryTypes([$types['nukeDoomed'], $types['nukeSurvivor']]);
    $entries->saveSection($section);
    $section = section();
}

Craft::$app->getProjectConfig()->saveModifiedConfigData();

// --- entries ---------------------------------------------------------------
$elements = Craft::$app->getElements();
$made = [];

for ($i = 1; $i <= 5; $i++) {
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $types['nukeDoomed']->id;
    $entry->title = "Doomed $i";
    $entry->enabled = true;
    $elements->saveElement($entry);
    $made[] = $entry;
}

// One child, so the promoted-descendant count has something to find.
$child = new Entry();
$child->sectionId = $section->id;
$child->typeId = $types['nukeSurvivor']->id;
$child->title = 'Child of Doomed 1';
$elements->saveElement($child);
Craft::$app->getStructures()->append($section->structureId, $child, $made[0]);

$survivor = new Entry();
$survivor->sectionId = $section->id;
$survivor->typeId = $types['nukeSurvivor']->id;
$survivor->title = 'Survivor';
$elements->saveElement($survivor);

// --- drafts and revisions --------------------------------------------------
$drafts = Craft::$app->getDrafts();
$revisions = Craft::$app->getRevisions();

foreach (array_slice($made, 0, 2) as $entry) {
    $drafts->createDraft($entry, 1, 'Fixture draft');
    $revisions->createRevision($entry, 1, 'Fixture revision');
    $entry->title .= ' (edited)';
    $elements->saveElement($entry);
    $revisions->createRevision($entry, 1, 'Second revision');
}

// --- relations -------------------------------------------------------------
// Written straight into the table: what matters is that rows exist pointing at the doomed
// entries from an element that survives, and building a relation field to do it would test
// Craft's field layer rather than Nuke's counting.
$fieldId = (new Query())->select(['id'])->from(Table::FIELDS)->scalar();

foreach (array_slice($made, 0, 3) as $entry) {
    Db::insert(Table::RELATIONS, [
        'fieldId' => $fieldId,
        'sourceId' => $survivor->id,
        'targetId' => $entry->id,
        'sortOrder' => 1,
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => \craft\helpers\StringHelper::UUID(),
    ]);
}

printf(
    "Fixtures ready.\n  section: %s (#%d)\n  doomed: %s\n  child: #%d\n  survivor: #%d\n",
    $section->handle,
    $section->id,
    implode(', ', array_map(fn($e) => '#' . $e->id, $made)),
    $child->id,
    $survivor->id,
);
