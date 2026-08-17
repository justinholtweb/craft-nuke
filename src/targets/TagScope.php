<?php

namespace justinholtweb\nuke\targets;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\Tag;
use justinholtweb\nuke\models\Target;

/**
 * Tags. Flat, cheap to delete, and the scope most likely to be full of things nothing points at.
 */
class TagScope extends BaseScope
{
    public static function handle(): string
    {
        return 'tags';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Tags');
    }

    public function elementType(): string
    {
        return Tag::class;
    }

    public function sourceLabel(): string
    {
        return Craft::t('nuke', 'Tag Groups');
    }

    public function sourceOptions(): array
    {
        return $this->optionsFrom(Craft::$app->getTags()->getAllTagGroups());
    }

    protected function baseQuery(Target $target): ElementQuery
    {
        $query = Tag::find();

        if ($groupIds = $target->ids('sourceIds')) {
            $query->groupId($groupIds);
        }

        return $query;
    }
}
