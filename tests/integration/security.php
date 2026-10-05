<?php
/**
 * What a non-admin with "Strike" can aim at, and who can change Nuke's settings — checked in the
 * plugin-testing harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-nuke/tests/integration/security.php
 *
 * Until 5.1.1 Nuke checked only its own permission. Someone granted "Strike" to clear out one
 * section could aim it at any section, volume or category group on the site, including ones they
 * couldn't see — and an explicit list of element IDs skipped the source filter altogether. And the
 * settings screens 403'd for an admin on an environment with allowAdminChanges off, instead of
 * showing read-only.
 *
 * Builds its own throwaway sections and tears them down; restores Nuke's stored settings, the
 * harness .env and config/nuke.php when it finishes. Nothing outside its own sections is ever
 * aimed at by a fire.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\services\ProjectConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\nuke\models\Target;
use justinholtweb\nuke\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$entries = Craft::$app->getEntries();
$run = bin2hex(random_bytes(3));
$password = 'Nuke-' . bin2hex(random_bytes(6));
$settingsPath = ProjectConfig::PATH_PLUGINS . '.nuke.settings';
$envFile = $root . '/.env';
$envBefore = file_get_contents($envFile);
$configFile = $root . '/config/nuke.php';
$configBefore = is_file($configFile) ? file_get_contents($configFile) : null;
$settingsBefore = Craft::$app->getProjectConfig()->get($settingsPath);
$cleanup = ['users' => []];

// Leftovers from a run that died half-way.
foreach ($entries->getAllSections() as $stale) {
    if (str_starts_with($stale->handle, 'nukeSec')) {
        $entries->deleteSection($stale);
    }
}
if ($staleType = $entries->getEntryTypeByHandle('nukeSecType')) {
    $entries->deleteEntryType($staleType);
}

$type = new EntryType(['name' => 'Nuke Sec Type', 'handle' => 'nukeSecType']);
$entries->saveEntryType($type) or throw new RuntimeException('entry type: ' . json_encode($type->getErrors()));

$sections = [];
foreach (['A', 'B', 'C'] as $letter) {
    $section = new Section([
        'name' => "Nuke Sec $letter $run",
        'handle' => "nukeSec$letter$run",
        'type' => Section::TYPE_CHANNEL,
    ]);
    $siteSettings = [];
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        $siteSettings[$site->id] = new Section_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]);
    }
    $section->setSiteSettings($siteSettings);
    $section->setEntryTypes([$type]);
    $entries->saveSection($section) or throw new RuntimeException("section $letter: " . json_encode($section->getErrors()));
    $sections[$letter] = $entries->getSectionByHandle("nukeSec$letter$run");

    foreach ([1, 2] as $i) {
        $entry = new Entry(['sectionId' => $sections[$letter]->id, 'typeId' => $type->id, 'title' => "$letter $i"]);
        Craft::$app->getElements()->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
    }
}
Craft::$app->getProjectConfig()->saveModifiedConfigData();

register_shutdown_function(function() use (&$cleanup, $sections, $envFile, $envBefore, $configFile, $configBefore, $settingsPath, $settingsBefore, $root) {
    file_put_contents($envFile, $envBefore);
    $configBefore === null ? @unlink($configFile) : file_put_contents($configFile, $configBefore);

    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    // Everything that touches project config runs in a fresh process. Once the web process has
    // saved settings, this one's copy is out of date: Craft refuses to write from it
    // (StaleResourceException), and set() would compare against it anyway.
    $restore = sys_get_temp_dir() . '/nuke-restore-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($restore, '<?php
require ' . var_export($root . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$entries = Craft::$app->getEntries();
foreach (' . var_export(array_map(fn($section) => $section->handle, array_values($sections)), true) . ' as $handle) {
    if ($section = $entries->getSectionByHandle($handle)) {
        $entries->deleteSection($section);
    }
}
if ($type = $entries->getEntryTypeByHandle("nukeSecType")) {
    $entries->deleteEntryType($type);
}
$pc = Craft::$app->getProjectConfig();
$pc->set(' . var_export($settingsPath, true) . ', ' . var_export($settingsBefore, true) . ', "Restore Nuke settings after security.php");
$pc->saveModifiedConfigData();
$pc->writeYamlFiles(true);
');
    exec('php ' . escapeshellarg($restore) . ' 2>&1', $out, $code);
    unlink($restore);
    $code === 0 or print("  ! Could not clean up: " . implode("\n", $out) . "\n");
});

$uid = static fn(string $letter) => $sections[$letter]->uid;
$cleaner = new User(['username' => "nuke-cleaner-$run", 'email' => "nuke-cleaner-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($cleaner, false);
Craft::$app->getUsers()->activateUser($cleaner);
Craft::$app->getUserPermissions()->saveUserPermissions($cleaner->id, [
    'accesscp', 'accessplugin-nuke', 'nuke:view', 'nuke:strike',
    // A: everything. B: their own entries only, not other people's. C: nothing.
    'viewentries:' . $uid('A'), 'viewpeerentries:' . $uid('A'), 'deleteentries:' . $uid('A'), 'deletepeerentries:' . $uid('A'),
    'viewentries:' . $uid('B'), 'deleteentries:' . $uid('B'),
]);
$cleanup['users'][] = $cleaner;

function client(string $username, string $password): Closure
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
        or throw new RuntimeException("Could not sign in as $username");

    return static function(string $method, string $uri, array $params = [], bool $json = true) use ($http, $csrf) {
        if ($method === 'GET') {
            return $http->get("index.php?p=admin/$uri");
        }

        return $http->post("index.php?p=admin/actions/$uri", [
            'headers' => $json ? ['Accept' => 'application/json'] : [],
            'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
        ]);
    };
}

$asCleaner = client($cleaner->username, $password);
$asAdmin = client('admin', 'claudepassword');

$preview = static function(Closure $as, array $params): array {
    $response = $as('POST', 'nuke/strike/preview', $params + ['scope' => 'entries']);
    $body = json_decode((string)$response->getBody(), true) ?: [];

    return ['status' => $response->getStatusCode(), 'canFire' => $body['canFire'] ?? null, 'html' => (string)($body['html'] ?? '')];
};
$idsIn = static fn(string $letter) => Entry::find()->sectionId($sections[$letter]->id)->status(null)->ids();
$sid = static fn(string $letter) => (string)$sections[$letter]->id;

echo "\nWhat “Strike” can reach\n";

check('a section they may delete everything in can be struck', function() use ($preview, $asCleaner, $sid) {
    $r = $preview($asCleaner, ['sourceIds' => [$sid('A')]]);

    return $r['canFire'] === true ?: json_encode($r['status']) . ' ' . strip_tags($r['html']);
});

check('a section they can only delete their own entries in can’t', function() use ($preview, $asCleaner, $sid, $sections) {
    $r = $preview($asCleaner, ['sourceIds' => [$sid('B')]]);

    return $r['canFire'] === false && str_contains($r['html'], $sections['B']->name) ?: 'canFire ' . var_export($r['canFire'], true);
});

check('…nor one they have no rights in at all', function() use ($preview, $asCleaner, $sid) {
    $r = $preview($asCleaner, ['sourceIds' => [$sid('C')]]);

    return $r['canFire'] === false ?: 'canFire ' . var_export($r['canFire'], true);
});

check('mixing an allowed section with a forbidden one is refused', function() use ($preview, $asCleaner, $sid) {
    $r = $preview($asCleaner, ['sourceIds' => [$sid('A'), $sid('C')]]);

    return $r['canFire'] === false ?: 'canFire ' . var_export($r['canFire'], true);
});

check('“all sections” is admin-only', function() use ($preview, $asCleaner) {
    $r = $preview($asCleaner, ['sourceIds' => '']);

    return $r['canFire'] === false && str_contains($r['html'], 'Only an admin can target all of them') ?: 'canFire ' . var_export($r['canFire'], true);
});

check('an explicit ID list is checked against where the elements really are', function() use ($preview, $asCleaner, $idsIn) {
    $own = $preview($asCleaner, ['elementIds' => implode(',', $idsIn('A'))]);
    $other = $preview($asCleaner, ['elementIds' => implode(',', $idsIn('C'))]);
    $mixed = $preview($asCleaner, ['elementIds' => implode(',', [...$idsIn('A'), ...$idsIn('C')])]);

    return $own['canFire'] === true && $other['canFire'] === false && $mixed['canFire'] === false
        ?: json_encode(['A' => $own['canFire'], 'C' => $other['canFire'], 'A+C' => $mixed['canFire']]);
});

check('firing at a forbidden section deletes nothing', function() use ($asCleaner, $sid, $idsIn, $plugin, $sections) {
    $target = new Target(['scope' => 'entries', 'sourceIds' => [(int)$sid('C')]]);
    $phrase = $plugin->getSettings()->confirmationPhraseFor($plugin->scopes->confirmationLabel($target));
    $before = count($idsIn('C'));
    $asCleaner('POST', 'nuke/strike/fire', [
        'scope' => 'entries', 'sourceIds' => [$sid('C')], 'confirmation' => $phrase,
        'backup' => '0', 'runGc' => '0', 'queue' => '0',
    ], false);

    return $before === 2 && count($idsIn('C')) === 2 ?: 'C now has ' . count($idsIn('C'));
});

check('the strike screen only offers the sections they may delete everything in', function() use ($asCleaner, $sid) {
    $html = (string)$asCleaner('GET', 'nuke/strike')->getBody();
    // The section checkboxes themselves — a name could also be in a flash from the refusal above.
    $offered = static fn(string $id) => preg_match('/<input[^>]*name="sourceIds\[\]"[^>]*value="' . $id . '"/', $html) === 1
        || preg_match('/<input[^>]*value="' . $id . '"[^>]*name="sourceIds\[\]"/', $html) === 1;

    return $offered($sid('A')) && !$offered($sid('B')) && !$offered($sid('C'))
        ?: json_encode(['A' => $offered($sid('A')), 'B' => $offered($sid('B')), 'C' => $offered($sid('C'))]);
});

check('an admin can still strike anywhere', function() use ($preview, $asAdmin, $sid) {
    $r = $preview($asAdmin, ['sourceIds' => [$sid('C')]]);

    return $r['canFire'] === true ?: strip_tags($r['html']);
});

echo "\nThe other scopes, in-process\n";

$scopes = $plugin->scopes;
$freshCleaner = static fn() => User::find()->id($cleaner->id)->status(null)->one();

check('nested entries (no section) by ID are admin-only', function() use ($scopes, $freshCleaner) {
    $nestedId = (new Query())->select('e.id')->from(['e' => '{{%entries}}'])->innerJoin(['el' => '{{%elements}}'], '[[el.id]] = [[e.id]]')
        ->where(['e.sectionId' => null, 'el.dateDeleted' => null])->scalar();

    if (!$nestedId) {
        return true; // nothing to check against in this harness
    }

    $errors = $scopes->permissionErrors(new Target(['scope' => 'entries', 'elementIds' => [(int)$nestedId]]), $freshCleaner());

    return str_contains(implode(' ', $errors), 'don’t belong to any') ?: json_encode($errors);
});

check('a category group needs deleteCategories', function() use ($scopes, $freshCleaner, $cleaner) {
    $group = Craft::$app->getCategories()->getAllGroups()[0] ?? null;
    if (!$group) {
        return true;
    }
    $target = new Target(['scope' => 'categories', 'sourceIds' => [(int)$group->id]]);
    $without = $scopes->permissionErrors($target, $freshCleaner());

    // Craft drops a permission whose parent isn't granted, so grant the whole chain.
    $perms = Craft::$app->getUserPermissions()->getPermissionsByUserId($cleaner->id);
    Craft::$app->getUserPermissions()->saveUserPermissions($cleaner->id, [...$perms, ...array_map(fn($p) => "$p:$group->uid", ['viewcategories', 'savecategories', 'deletecategories'])]);
    $granted = $freshCleaner()->can("deleteCategories:$group->uid");
    $with = $scopes->permissionErrors($target, $freshCleaner());
    Craft::$app->getUserPermissions()->saveUserPermissions($cleaner->id, $perms);

    return $granted && $without !== [] && $with === [] ?: json_encode(['granted' => $granted, 'without' => $without, 'with' => $with]);
});

check('a volume needs deleteAssets and deletePeerAssets', function() use ($scopes, $freshCleaner, $cleaner) {
    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
    if (!$volume) {
        return true;
    }
    $target = new Target(['scope' => 'assets', 'sourceIds' => [(int)$volume->id]]);
    $perms = Craft::$app->getUserPermissions()->getPermissionsByUserId($cleaner->id);

    $own = array_map(fn($p) => "$p:$volume->uid", ['viewassets', 'saveassets', 'deleteassets']);
    $peer = array_map(fn($p) => "$p:$volume->uid", ['viewpeerassets', 'savepeerassets', 'deletepeerassets']);

    Craft::$app->getUserPermissions()->saveUserPermissions($cleaner->id, [...$perms, ...$own]);
    $ownOnly = $scopes->permissionErrors($target, $freshCleaner());
    Craft::$app->getUserPermissions()->saveUserPermissions($cleaner->id, [...$perms, ...$own, ...$peer]);
    $granted = $freshCleaner()->can("deletePeerAssets:$volume->uid") && $freshCleaner()->can("deleteAssets:$volume->uid");
    $both = $scopes->permissionErrors($target, $freshCleaner());
    Craft::$app->getUserPermissions()->saveUserPermissions($cleaner->id, $perms);

    return $granted && $ownOnly !== [] && $both === [] ?: json_encode(['granted' => $granted, 'own only' => $ownOnly, 'both' => $both]);
});

check('tags need nothing beyond Nuke’s own permission — Craft lets anyone delete a tag', function() use ($scopes, $freshCleaner) {
    $group = Craft::$app->getTags()->getAllTagGroups()[0] ?? null;
    if (!$group) {
        return true;
    }

    return $scopes->permissionErrors(new Target(['scope' => 'tags', 'sourceIds' => [(int)$group->id]]), $freshCleaner()) === [] ?: 'refused';
});

check('an admin passes the wide targets a non-admin can’t: everything, and nested entries by ID', function() use ($scopes) {
    $admin = User::find()->admin(true)->status(null)->one();
    $nestedId = (int)(new Query())->select('id')->from('{{%entries}}')->where(['sectionId' => null])->scalar();
    $all = $scopes->permissionErrors(new Target(['scope' => 'entries']), $admin);
    $nested = $nestedId ? $scopes->permissionErrors(new Target(['scope' => 'entries', 'elementIds' => [$nestedId]]), $admin) : [];

    return $all === [] && $nested === [] ?: json_encode(['all' => $all, 'nested' => $nested]);
});

check('the console and the queue aren’t checked — no user is passed', function() use ($plugin, $sid) {
    $radius = $plugin->detonator->preview(new Target(['scope' => 'entries', 'sourceIds' => [(int)$sid('C')]]));

    return $radius->canFire() ?: implode(' ', $radius->errors);
});

echo "\nSettings\n";

check('an admin sees the settings form', function() use ($asAdmin) {
    $html = (string)$asAdmin('GET', 'nuke/settings')->getBody();

    return str_contains($html, 'id="main-form"') ?: 'no form';
});

check('a save doesn’t copy config/nuke.php overrides into project config', function() use ($asAdmin, $configFile, $settingsPath) {
    file_put_contents($configFile, "<?php\nreturn ['maxElementsPerStrike' => 4242];\n");
    try {
        $status = $asAdmin('POST', 'nuke/settings/save', ['settings' => ['requireConfirmation' => '1']], false)->getStatusCode();
    } finally {
        @unlink($configFile);
    }
    $stored = json_decode((string)(new Query())->select('value')->from('{{%projectconfig}}')->where(['path' => $settingsPath . '.maxElementsPerStrike'])->scalar(), true);

    return in_array($status, [200, 302], true) && $stored !== 4242 ?: "status $status, stored " . var_export($stored, true);
});

$envOff = preg_replace('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', 'CRAFT_ALLOW_ADMIN_CHANGES=false', $envBefore, -1, $replaced);
file_put_contents($envFile, $replaced ? $envOff : rtrim($envBefore) . "\nCRAFT_ALLOW_ADMIN_CHANGES=false\n");

check('with allowAdminChanges off an admin sees every settings screen read-only', function() use ($asAdmin) {
    $out = [];
    foreach (['nuke/settings', 'nuke/settings/sweepers', 'nuke/settings/schedule'] as $uri) {
        $response = $asAdmin('GET', $uri);
        $html = (string)$response->getBody();
        if ($response->getStatusCode() !== 200 || str_contains($html, 'id="main-form"') || !str_contains($html, '<fieldset disabled>')) {
            $out[] = "$uri: " . $response->getStatusCode();
        }
    }

    return $out === [] ?: implode(', ', $out);
});

check('…and can’t save', function() use ($asAdmin) {
    $status = $asAdmin('POST', 'nuke/settings/save', ['settings' => ['requireConfirmation' => '1']], false)->getStatusCode();

    return $status === 403 ?: "status $status";
});

file_put_contents($envFile, $envBefore);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
