<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use justinholtweb\nuke\models\SweepResult;

/**
 * Removes asset folders with nothing in them.
 *
 * Off by default, and deliberately so: an empty folder is often somewhere an editor is *about* to
 * upload into, and deleting the folder they were told to use is a worse outcome than a tidy tree.
 * Turn it on after a migration, when the empties are genuinely leftovers.
 *
 * Root folders are never candidates — a volume without its root folder is a broken volume.
 */
class EmptyFoldersSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'emptyFolders';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Empty asset folders');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Deletes asset folders that hold no files and no subfolders. Off by default — an empty folder is usually somewhere an editor was told to upload to. Volume roots are never touched.');
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
        return ['enabled' => false];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $ids = $this->emptyFolderIds();
        $result->found = count($ids);

        if ($result->found === 0) {
            return;
        }

        foreach ($this->folderPaths($ids) as $path) {
            $this->addSample($result, $path);
        }

        if ($dryRun) {
            return;
        }

        // Through the Assets service rather than a DELETE, so the directory on the volume goes
        // too and the folder tree cache is invalidated.
        Craft::$app->getAssets()->deleteFoldersByIds($ids, true);
        $result->removed = count($ids);
    }

    /**
     * Folder IDs with no assets and no children.
     *
     * @return int[]
     */
    private function emptyFolderIds(): array
    {
        $withAssets = (new Query())
            ->from(['a' => Table::ASSETS])
            ->where('[[a.folderId]] = [[f.id]]');

        $withChildren = (new Query())
            ->from(['c' => Table::VOLUMEFOLDERS])
            ->where('[[c.parentId]] = [[f.id]]');

        $ids = (new Query())
            ->select(['f.id'])
            ->from(['f' => Table::VOLUMEFOLDERS])
            ->where(['not', ['f.parentId' => null]])
            ->andWhere(['not', ['f.volumeId' => null]])
            ->andWhere(['not exists', $withAssets])
            ->andWhere(['not exists', $withChildren])
            ->column();

        return array_map('intval', $ids);
    }

    /**
     * @param int[] $ids
     * @return string[]
     */
    private function folderPaths(array $ids): array
    {
        return (new Query())
            ->select(['path'])
            ->from(Table::VOLUMEFOLDERS)
            ->where(['id' => array_slice($ids, 0, 10)])
            ->column();
    }
}
