<?php

namespace justinholtweb\nuke\targets;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\models\Target;

/**
 * Users. Off by default, and hedged even when switched on.
 *
 * Deleting a user is not like deleting an entry: it cascades into authorship, addresses, session
 * rows and permission assignments, and Craft offers to reassign that content rather than lose it.
 * A bulk delete cannot ask which user to reassign to, so this scope is deliberately the most
 * awkward one in the plugin to use.
 */
class UserScope extends BaseScope
{
    public static function handle(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Users');
    }

    public function elementType(): string
    {
        return User::class;
    }

    public function isAvailable(): bool
    {
        // Unlike the other scopes, no sources are needed: every site has users, and "users in no
        // group" is a perfectly ordinary target.
        return true;
    }

    public function sourceLabel(): string
    {
        return Craft::t('nuke', 'User Groups');
    }

    public function sourceOptions(): array
    {
        return $this->optionsFrom(Craft::$app->getUserGroups()->getAllGroups());
    }

    public function statusOptions(): array
    {
        return [
            ['label' => Craft::t('nuke', 'Any status'), 'value' => Target::STATUS_ANY],
            ['label' => Craft::t('nuke', 'Active'), 'value' => User::STATUS_ACTIVE],
            ['label' => Craft::t('nuke', 'Pending'), 'value' => User::STATUS_PENDING],
            ['label' => Craft::t('nuke', 'Suspended'), 'value' => User::STATUS_SUSPENDED],
            ['label' => Craft::t('nuke', 'Locked'), 'value' => User::STATUS_LOCKED],
            ['label' => Craft::t('nuke', 'Inactive'), 'value' => User::STATUS_INACTIVE],
        ];
    }

    protected function baseQuery(Target $target): ElementQuery
    {
        $query = User::find();

        if ($groupIds = $target->ids('sourceIds')) {
            $query->groupId($groupIds);
        }

        // Never the operator's own account. Deleting it mid-run logs them out, which aborts the
        // very request doing the deleting, and leaves the run ledger with no ending.
        $currentId = Craft::$app->getUser()->getId();

        if ($currentId) {
            $query->andWhere(['not', ['elements.id' => $currentId]]);
        }

        return $query;
    }

    /**
     * Users are admin-only, and validate() says so; there are no group permissions to check.
     */
    public function permissionErrors(Target $target, User $user): array
    {
        return [];
    }

    public function sourceOptionsFor(User $user): array
    {
        return $this->sourceOptions();
    }

    public function validate(Target $target, Settings $settings): array
    {
        $errors = parent::validate($target, $settings);

        if (!$settings->allowUserStrikes) {
            $errors[] = Craft::t('nuke', 'User strikes are switched off. Turn them on in Nuke’s settings if this is really what you want.');
        }

        if (!Craft::$app->getUser()->getIsAdmin()) {
            $errors[] = Craft::t('nuke', 'Only an admin can delete users.');
        }

        return $errors;
    }

    public function warnings(Target $target): array
    {
        $warnings = [
            Craft::t('nuke', 'Content authored by these users is not reassigned. Craft’s own user delete offers that; a bulk strike cannot.'),
            Craft::t('nuke', 'Your own account is excluded from every user strike.'),
        ];

        $admins = (int)User::find()
            ->admin(true)
            ->status(null)
            ->count();

        if ($admins > 0) {
            $warnings[] = Craft::t('nuke', 'Admin accounts are matched like any other. Check the sample before firing.');
        }

        return $warnings;
    }
}
