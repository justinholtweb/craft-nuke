<?php

namespace justinholtweb\nuke;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\services\Backups;
use justinholtweb\nuke\services\Detonator;
use justinholtweb\nuke\services\Notifications;
use justinholtweb\nuke\services\Runs;
use justinholtweb\nuke\services\Schedules;
use justinholtweb\nuke\services\Scopes;
use justinholtweb\nuke\services\Sweep;
use justinholtweb\nuke\services\Sweepers;
use justinholtweb\nuke\variables\NukeVariable;
use yii\base\Event;

/**
 * Nuke — delete content in bulk, then keep the site clean.
 *
 * The plugin does two related jobs. A **strike** deletes a deliberately chosen set of content
 * (a section, a volume, a user group) together with its drafts, revisions and relations. A
 * **sweep** is the recurring janitorial pass: unreferenced assets, orphaned rows, expired
 * backups and logs, temp files, and Craft's own garbage collection.
 *
 * Everything destructive in here is preceded by a dry run. See {@see Detonator} for why the
 * preview and the execution deliberately share one code path.
 *
 * @property-read Detonator $detonator
 * @property-read Sweep $sweep
 * @property-read Sweepers $sweepers
 * @property-read Runs $runs
 * @property-read Notifications $notifications
 * @property-read Scopes $scopes
 * @property-read Schedules $schedules
 * @property-read Backups $backups
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /** See the dashboard, run history and dry-run previews. */
    public const PERMISSION_VIEW = 'nuke:view';

    /** Actually delete content with a strike. Deliberately separate from `view`. */
    public const PERMISSION_STRIKE = 'nuke:strike';

    /** Run housekeeping sweeps. */
    public const PERMISSION_SWEEP = 'nuke:sweep';

    /** Permanently delete rather than moving to the trash. */
    public const PERMISSION_HARD_DELETE = 'nuke:hardDelete';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'nuke';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'detonator' => Detonator::class,
                'sweep' => Sweep::class,
                'sweepers' => Sweepers::class,
                'runs' => Runs::class,
                'notifications' => Notifications::class,
                'scopes' => Scopes::class,
                'schedules' => Schedules::class,
                'backups' => Backups::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
        $this->registerTwigVariable();
        $this->registerScheduleTrigger();
    }

    /**
     * Every edition check goes through here rather than calling `is()` directly, so the
     * gate is one method to find and one method to change.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    /**
     * Nuke keeps its own log. A destructive tool needs an audit trail that survives the
     * database it was deleting from, and `storage/logs/nuke.log` is that trail — the run
     * ledger in the database is the convenient copy, not the authoritative one.
     */
    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 30,
        ]);
    }

    /**
     * Exposes `craft.nuke` to templates.
     */
    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('nuke', NukeVariable::class);
            }
        );
    }

    /**
     * Fires due sweeps from control panel traffic when the site has no cron.
     *
     * Only ever queues a job — nothing is deleted inside a page request. The check itself is
     * a single cached read, so it costs nothing on the requests where nothing is due.
     */
    private function registerScheduleTrigger(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (!$this->isPro() || !$settings->scheduleEnabled || $settings->scheduleTrigger !== Settings::TRIGGER_WEB) {
            return;
        }

        $request = Craft::$app->getRequest();

        if (!$request->getIsCpRequest() || $request->getIsAjax() || $request->getIsConsoleRequest()) {
            return;
        }

        Craft::$app->onAfterRequest(function() {
            $this->schedules->queueIfDue();
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [
            'dashboard' => ['label' => Craft::t('nuke', 'Overview'), 'url' => 'nuke'],
        ];

        if ($user->checkPermission(self::PERMISSION_STRIKE)) {
            $subnav['strike'] = ['label' => Craft::t('nuke', 'Strike'), 'url' => 'nuke/strike'];
        }

        if ($user->checkPermission(self::PERMISSION_SWEEP)) {
            $subnav['sweep'] = ['label' => Craft::t('nuke', 'Sweep'), 'url' => 'nuke/sweep'];
        }

        $subnav['runs'] = ['label' => Craft::t('nuke', 'History'), 'url' => 'nuke/runs'];

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('nuke', 'Settings'), 'url' => 'nuke/settings'];
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('nuke/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['nuke'] = 'nuke/dashboard/index';
                $event->rules['nuke/strike'] = 'nuke/strike/index';
                $event->rules['nuke/strike/preview'] = 'nuke/strike/preview';
                $event->rules['nuke/sweep'] = 'nuke/sweep/index';
                $event->rules['nuke/runs'] = 'nuke/runs/index';
                $event->rules['nuke/runs/<id:\d+>'] = 'nuke/runs/detail';
                $event->rules['nuke/settings'] = 'nuke/settings/index';
                $event->rules['nuke/settings/sweepers'] = 'nuke/settings/sweepers';
                $event->rules['nuke/settings/schedule'] = 'nuke/settings/schedule';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('nuke', 'Nuke'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('nuke', 'View previews and run history'),
                            'nested' => [
                                self::PERMISSION_SWEEP => [
                                    'label' => Craft::t('nuke', 'Run housekeeping sweeps'),
                                ],
                                self::PERMISSION_STRIKE => [
                                    'label' => Craft::t('nuke', 'Delete content with a strike'),
                                    'nested' => [
                                        self::PERMISSION_HARD_DELETE => [
                                            'label' => Craft::t('nuke', 'Delete permanently, bypassing the trash'),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }
}
