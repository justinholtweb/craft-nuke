<?php

namespace justinholtweb\nuke\targets;

use Craft;
use craft\elements\Category;
use craft\elements\db\ElementQuery;
use justinholtweb\nuke\models\Target;

/**
 * Categories. Every category group is a structure, so a target that names a handful of top-level
 * categories can take the whole tree with it.
 */
class CategoryScope extends BaseScope
{
    public static function handle(): string
    {
        return 'categories';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Categories');
    }

    public function elementType(): string
    {
        return Category::class;
    }

    public function sourceLabel(): string
    {
        return Craft::t('nuke', 'Category Groups');
    }

    public function sourceOptions(): array
    {
        return $this->optionsFrom(Craft::$app->getCategories()->getAllGroups());
    }

    protected function baseQuery(Target $target): ElementQuery
    {
        $query = Category::find();

        if ($groupIds = $target->ids('sourceIds')) {
            $query->groupId($groupIds);
        }

        return $query;
    }

    public function deletePermissions(int $sourceId): ?array
    {
        $group = Craft::$app->getCategories()->getGroupById($sourceId);

        return $group ? ["deleteCategories:$group->uid"] : null;
    }

    protected function sourceColumn(): ?string
    {
        return 'categories.groupId';
    }

    public function hasStructure(Target $target): bool
    {
        return true;
    }
}
