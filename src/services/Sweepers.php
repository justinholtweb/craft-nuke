<?php

namespace justinholtweb\nuke\services;

use Craft;
use craft\base\Component;
use justinholtweb\nuke\events\RegisterSweepersEvent;
use justinholtweb\nuke\Plugin;
use justinholtweb\nuke\sweepers\ActivitySweeper;
use justinholtweb\nuke\sweepers\AnnouncementsSweeper;
use justinholtweb\nuke\sweepers\AssetIndexingSweeper;
use justinholtweb\nuke\sweepers\BackupsSweeper;
use justinholtweb\nuke\sweepers\BulkOpsSweeper;
use justinholtweb\nuke\sweepers\ChangelogSweeper;
use justinholtweb\nuke\sweepers\CompiledFilesSweeper;
use justinholtweb\nuke\sweepers\ConfigBackupsSweeper;
use justinholtweb\nuke\sweepers\DeprecationsSweeper;
use justinholtweb\nuke\sweepers\DraftsSweeper;
use justinholtweb\nuke\sweepers\EmptyFoldersSweeper;
use justinholtweb\nuke\sweepers\GarbageCollectionSweeper;
use justinholtweb\nuke\sweepers\LogsSweeper;
use justinholtweb\nuke\sweepers\OrphanedRelationsSweeper;
use justinholtweb\nuke\sweepers\QueueSweeper;
use justinholtweb\nuke\sweepers\RevisionsSweeper;
use justinholtweb\nuke\sweepers\SearchIndexSweeper;
use justinholtweb\nuke\sweepers\SessionsSweeper;
use justinholtweb\nuke\sweepers\SweeperInterface;
use justinholtweb\nuke\sweepers\TempFilesSweeper;
use justinholtweb\nuke\sweepers\TokensSweeper;
use justinholtweb\nuke\sweepers\TransformIndexSweeper;
use justinholtweb\nuke\sweepers\TrashedElementsSweeper;
use justinholtweb\nuke\sweepers\UnusedAssetsSweeper;
use yii\base\InvalidArgumentException;

/**
 * The register of housekeeping tasks.
 *
 * Order matters, and it is the order of this list. Content first, because deleting an element is
 * what orphans the rows the database sweepers then collect; files next; Craft's own garbage
 * collector last, so it picks up everything the rest left behind in a single pass.
 */
class Sweepers extends Component
{
    /**
     * @event RegisterSweepersEvent Raised when the sweeper list is being assembled.
     */
    public const EVENT_REGISTER_SWEEPERS = 'registerSweepers';

    /** @var SweeperInterface[]|null Keyed by handle, in run order. */
    private ?array $sweepers = null;

    /**
     * @return SweeperInterface[] Keyed by handle, in run order.
     */
    public function all(): array
    {
        if ($this->sweepers !== null) {
            return $this->sweepers;
        }

        $event = new RegisterSweepersEvent([
            'sweepers' => [
                // Content — the things that create work for everything below.
                TrashedElementsSweeper::class,
                DraftsSweeper::class,
                RevisionsSweeper::class,
                UnusedAssetsSweeper::class,
                EmptyFoldersSweeper::class,

                // Database rows nothing points at any more.
                OrphanedRelationsSweeper::class,
                SearchIndexSweeper::class,
                ChangelogSweeper::class,
                ActivitySweeper::class,
                DeprecationsSweeper::class,
                QueueSweeper::class,
                SessionsSweeper::class,
                TokensSweeper::class,
                AnnouncementsSweeper::class,
                AssetIndexingSweeper::class,
                TransformIndexSweeper::class,
                BulkOpsSweeper::class,

                // Files under storage/.
                BackupsSweeper::class,
                LogsSweeper::class,
                TempFilesSweeper::class,
                CompiledFilesSweeper::class,
                ConfigBackupsSweeper::class,

                // Craft's own, last.
                GarbageCollectionSweeper::class,
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_SWEEPERS, $event);

        $this->sweepers = [];

        foreach ($event->sweepers as $sweeper) {
            if (is_string($sweeper)) {
                $sweeper = Craft::createObject($sweeper);
            }

            if (!$sweeper instanceof SweeperInterface) {
                continue;
            }

            $this->sweepers[$sweeper::handle()] = $sweeper;
        }

        return $this->sweepers;
    }

    /**
     * Sweepers this site can run, in run order.
     *
     * @return SweeperInterface[] Keyed by handle.
     */
    public function available(): array
    {
        return array_filter($this->all(), fn(SweeperInterface $s) => $s->isAvailable());
    }

    /**
     * Sweepers that are available *and* switched on.
     *
     * @return SweeperInterface[] Keyed by handle.
     */
    public function enabled(): array
    {
        return array_filter(
            $this->available(),
            fn(SweeperInterface $s) => !empty($this->configFor($s)['enabled']),
        );
    }

    /**
     * @return SweeperInterface[][] Keyed by group, then by handle.
     */
    public function byGroup(): array
    {
        $groups = [];

        foreach ($this->all() as $handle => $sweeper) {
            $groups[$sweeper->group()][$handle] = $sweeper;
        }

        return $groups;
    }

    public function get(string $handle): SweeperInterface
    {
        $sweepers = $this->all();

        if (!isset($sweepers[$handle])) {
            throw new InvalidArgumentException("No Nuke sweeper with the handle “{$handle}”.");
        }

        return $sweepers[$handle];
    }

    public function has(string $handle): bool
    {
        return isset($this->all()[$handle]);
    }

    /**
     * A sweeper's effective configuration: its own defaults with the site's overrides on top.
     *
     * @return array<string, mixed>
     */
    public function configFor(SweeperInterface $sweeper): array
    {
        /** @var \justinholtweb\nuke\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings->configFor($sweeper::handle(), $sweeper->defaultConfig());
    }

    /**
     * Human labels for the group keys, so the control panel and the console agree.
     *
     * @return array<string, string>
     */
    public function groupLabels(): array
    {
        return [
            SweeperInterface::GROUP_CONTENT => Craft::t('nuke', 'Content'),
            SweeperInterface::GROUP_DATABASE => Craft::t('nuke', 'Database'),
            SweeperInterface::GROUP_FILES => Craft::t('nuke', 'Files'),
            SweeperInterface::GROUP_CRAFT => Craft::t('nuke', 'Craft'),
        ];
    }
}
