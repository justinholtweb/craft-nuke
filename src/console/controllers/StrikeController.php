<?php

namespace justinholtweb\nuke\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\Console;
use justinholtweb\nuke\models\Target;
use justinholtweb\nuke\Plugin;
use yii\console\ExitCode;

/**
 * Deletes content from the command line.
 *
 * The console default is the opposite of the control panel's: `--dry-run` is on unless you turn
 * it off. A command that deletes by default is a command that deletes when someone runs it to see
 * what the arguments are.
 *
 * ```
 * php craft nuke/strike/fire entries --sources=news --updated-before="-2 years"
 * php craft nuke/strike/fire entries --sources=news --updated-before="-2 years" --dry-run=0 --force
 * php craft nuke/strike/fire entries --authors=jsmith
 * ```
 */
class StrikeController extends Controller
{
    public $defaultAction = 'fire';

    /** @var bool Report what would be deleted without deleting it. On unless explicitly disabled. */
    public bool $dryRun = true;

    /** @var bool Skip the interactive confirmation. Required for anything non-interactive. */
    public bool $force = false;

    /** @var string|null Comma-separated source handles — section, volume or group handles. */
    public ?string $sources = null;

    /** @var string|null Comma-separated entry type handles. */
    public ?string $types = null;

    /** @var string|null Comma-separated site handles. */
    public ?string $sites = null;

    /**
     * @var string|null Comma-separated usernames, emails or user IDs whose content to target.
     *                  Users in Craft's trash are found too.
     */
    public ?string $authors = null;

    /** @var string|null Element status to match. */
    public ?string $status = null;

    /** @var string|null Only elements last updated before this. Accepts `-90 days`. */
    public ?string $updatedBefore = null;

    /** @var string|null Only elements created before this. */
    public ?string $createdBefore = null;

    /** @var int|null Stop after this many elements. */
    public ?int $limit = null;

    /** @var bool Bypass the trash and delete permanently. */
    public bool $hard = false;

    /** @var bool Take a database backup first. */
    public bool $backup = true;

    /** @var bool Permanently remove the drafts and revisions of matched elements. */
    public bool $purgeHistory = false;

    /** @var bool Remove relation rows pointing at the deleted elements. */
    public bool $deleteRelations = false;

    /** @var bool Run Craft's garbage collector afterwards. */
    public bool $gc = true;

    /** @var string|null A note recorded against the run. */
    public ?string $note = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'dryRun', 'force', 'sources', 'types', 'sites', 'authors', 'status',
            'updatedBefore', 'createdBefore', 'limit', 'hard', 'backup',
            'purgeHistory', 'deleteRelations', 'gc', 'note',
        ]);
    }

    /**
     * Lists the scopes this site can target, with their sources.
     */
    public function actionScopes(): int
    {
        foreach (Plugin::getInstance()->scopes->available() as $handle => $scope) {
            $this->stdout("$handle", Console::FG_YELLOW, Console::BOLD);
            $this->stdout(' — ' . $scope->label() . "\n");
            $this->stdout('  ' . $scope->sourceLabel() . ": ");

            $handles = array_map(fn(array $o) => $o['handle'], $scope->sourceOptions());
            $this->stdout(($handles ? implode(', ', $handles) : '(none)') . "\n\n");
        }

        return ExitCode::OK;
    }

    /**
     * Previews or runs a strike.
     *
     * @param string $scope The scope handle — `entries`, `assets`, `users`, and so on.
     */
    public function actionFire(string $scope = 'entries'): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->allowConsoleStrikes) {
            $this->stderr("Console strikes are switched off in Nuke's settings.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        if (!$plugin->scopes->has($scope)) {
            $this->stderr("No such scope: $scope. Try `nuke/strike/scopes`.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $authorIds = $this->resolveAuthors();

        if ($authorIds === null) {
            return ExitCode::USAGE;
        }

        $target = $this->buildTarget($scope);
        $target->authorIds = $authorIds;
        $radius = $plugin->detonator->preview($target);

        $this->stdout("\n" . $radius->description . "\n\n", Console::FG_CYAN);

        foreach ($radius->errors as $error) {
            $this->stderr("  ✗ $error\n", Console::FG_RED);
        }

        if ($radius->errors !== []) {
            return ExitCode::DATAERR;
        }

        $this->report($radius);

        foreach ($radius->warnings as $warning) {
            $this->stdout("  ! $warning\n", Console::FG_YELLOW);
        }

        if ($radius->isEmpty()) {
            $this->stdout("\nNothing matched.\n");
            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout("\nDry run — nothing was deleted. Pass --dry-run=0 to run it.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        if (!$this->force && !$this->confirm("\nDelete {$radius->total()} things?")) {
            $this->stdout("Cancelled.\n");
            return ExitCode::OK;
        }

        ['runId' => $runId, 'outcome' => $outcome] = $plugin->detonator->launch($target, $radius);

        $this->stdout(sprintf(
            "\nRun #%d: %d elements, %d drafts, %d revisions, %d relations, %d failed, %.1fs\n",
            $runId,
            $outcome->elements,
            $outcome->drafts,
            $outcome->revisions,
            $outcome->relations,
            $outcome->failed,
            $outcome->duration,
        ), $outcome->failed > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        if ($outcome->backupPath !== null) {
            $this->stdout("Backup: {$outcome->backupPath}\n");
        }

        foreach ($outcome->errors as $error) {
            $this->stderr("  ✗ $error\n", Console::FG_RED);
        }

        return $outcome->failed > 0 ? ExitCode::SOFTWARE : ExitCode::OK;
    }

    private function report(\justinholtweb\nuke\models\BlastRadius $radius): void
    {
        $lines = [
            'elements' => $radius->elements,
            'drafts' => $radius->drafts,
            'revisions' => $radius->revisions,
            'incoming relations' => $radius->incomingRelations,
            'outgoing relations' => $radius->outgoingRelations,
        ];

        if ($radius->files > 0) {
            $lines['files'] = $radius->files;
        }

        if ($radius->promotedDescendants > 0) {
            $lines['descendants moved up'] = $radius->promotedDescendants;
        }

        if ($radius->collateralCount > 0) {
            $lines['elements left referencing them'] = $radius->collateralCount;
        }

        foreach ($lines as $label => $value) {
            $this->stdout(sprintf("  %-32s %s\n", $label, number_format($value)));
        }
    }

    private function buildTarget(string $scope): Target
    {
        $target = new Target();
        $target->scope = $scope;
        $target->sourceIds = $this->resolveSources($scope);
        $target->typeIds = $this->resolveTypes($scope);
        $target->siteIds = $this->resolveSites();
        $target->status = $this->status ?: Target::STATUS_ANY;
        $target->updatedBefore = $this->updatedBefore;
        $target->createdBefore = $this->createdBefore;
        $target->limit = $this->limit;
        $target->hardDelete = $this->hard;
        $target->backup = $this->backup;
        $target->purgeHistory = $this->purgeHistory;
        $target->deleteRelations = $this->deleteRelations;
        $target->runGc = $this->gc;
        $target->note = $this->note ?? 'Console strike';

        return $target;
    }

    /**
     * @return int[]
     */
    private function resolveSources(string $scope): array
    {
        if ($this->sources === null) {
            return [];
        }

        $wanted = array_map('trim', explode(',', $this->sources));
        $ids = [];

        foreach (Plugin::getInstance()->scopes->get($scope)->sourceOptions() as $option) {
            if (in_array($option['handle'], $wanted, true)) {
                $ids[] = $option['value'];
            }
        }

        return $ids;
    }

    /**
     * @return int[]
     */
    private function resolveTypes(string $scope): array
    {
        if ($this->types === null) {
            return [];
        }

        $wanted = array_map('trim', explode(',', $this->types));
        $ids = [];

        foreach (Plugin::getInstance()->scopes->get($scope)->typeOptions() as $option) {
            if (in_array($option['handle'], $wanted, true)) {
                $ids[] = $option['value'];
            }
        }

        return $ids;
    }

    /**
     * The users `--authors` names, or null if any of them can't be found.
     *
     * Unlike the other lists, an unknown name here is an error rather than something to drop. A
     * typo that resolved to no authors would be no author filter at all — every author's content
     * instead of one person's.
     *
     * @return int[]|null
     */
    private function resolveAuthors(): ?array
    {
        if ($this->authors === null || trim($this->authors) === '') {
            return [];
        }

        $ids = [];

        foreach (array_filter(array_map('trim', explode(',', $this->authors))) as $name) {
            $query = User::find()->status(null)->trashed(null);

            if (ctype_digit($name)) {
                $query->id((int)$name);
            } elseif (str_contains($name, '@')) {
                $query->email($name);
            } else {
                $query->username($name);
            }

            $user = $query->one();

            if ($user === null) {
                $this->stderr("No user matches “{$name}”. A permanently deleted user can’t be targeted — their authorship went with the account.\n", Console::FG_RED);
                return null;
            }

            $ids[] = (int)$user->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int[]
     */
    private function resolveSites(): array
    {
        if ($this->sites === null) {
            return [];
        }

        $ids = [];

        foreach (array_map('trim', explode(',', $this->sites)) as $handle) {
            $site = Craft::$app->getSites()->getSiteByHandle($handle);

            if ($site) {
                $ids[] = (int)$site->id;
            }
        }

        return $ids;
    }
}
