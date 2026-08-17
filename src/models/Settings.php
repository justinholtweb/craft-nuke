<?php

namespace justinholtweb\nuke\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Nuke's plugin settings.
 *
 * Nothing here is marked `required`. A `required` rule on any one setting makes
 * `savePluginSettings()` fail wholesale, so on a fresh install *no* setting can be saved until
 * that one is filled in — values are validated for correctness only when they're present.
 *
 * The defaults are deliberately the timid ones. A plugin that deletes things should arrive
 * configured to hesitate: confirmation on, backup on, hard delete off, users off limits. An
 * operator who wants it faster has to say so.
 */
class Settings extends Model
{
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_WEB = 'web';

    public const NOTIFY_ALWAYS = 'always';
    public const NOTIFY_CHANGES = 'changes';
    public const NOTIFY_PROBLEMS = 'problems';

    // ---------------------------------------------------------------------
    // Guardrails
    // ---------------------------------------------------------------------

    /**
     * @var bool Whether a strike must be armed by typing a confirmation phrase.
     *
     * The point isn't that typing is hard to do — it's that it can't be done by accident, by a
     * double-click, or by a browser restoring a form on back-navigation.
     */
    public bool $requireConfirmation = true;

    /**
     * @var string What has to be typed. `{scope}` is replaced with the scope's own handle, which
     *             makes the confirmation specific to the thing being deleted rather than a phrase
     *             muscle memory can produce without reading.
     */
    public string $confirmationPhrase = '{scope}';

    /**
     * @var bool Whether to take a database backup immediately before a strike deletes anything.
     *
     * The backup path is recorded on the run, so "undo" is a documented restore rather than a
     * hunt through storage/backups.
     */
    public bool $backupBeforeStrike = true;

    /**
     * @var bool Whether strikes hard-delete by default.
     *
     * Off. A soft delete puts elements in Craft's trash, where they stay recoverable until
     * garbage collection's `softDeleteDuration` elapses — that window is the real safety net,
     * and it costs nothing to keep.
     */
    public bool $hardDeleteByDefault = false;

    /**
     * @var bool Whether users may be struck at all.
     *
     * Deleting users cascades into authorship, addresses and permissions, and is the one scope
     * where "I'll just restore the backup" is most likely to be wrong. Opt in explicitly.
     */
    public bool $allowUserStrikes = false;

    /**
     * @var string[] Handles of sections, volumes, category/tag groups and user groups that no
     *               strike may ever target, whatever the form says. Checked server-side.
     */
    public array $protectedScopes = [];

    /**
     * @var int A ceiling on how many elements one strike may delete. 0 removes the ceiling.
     *
     * Intended to make a mis-scoped run fail loudly at the preview instead of quietly at 200,000
     * rows. Raising it is a deliberate act.
     */
    public int $maxElementsPerStrike = 10000;

    // ---------------------------------------------------------------------
    // Execution
    // ---------------------------------------------------------------------

    /**
     * @var int How many elements to delete per batch. Each batch is its own transaction, so a
     *          failure part-way leaves completed batches committed and recorded.
     */
    public int $batchSize = 100;

    /**
     * @var bool Whether new strikes default to clearing rows in the `relations` table.
     *
     * Only affects soft deletes — a permanent delete always clears them, because Craft has no
     * foreign key on `relations.targetId` and would otherwise leave rows pointing at nothing.
     * For a soft delete, keeping them is what makes a restore restore the relationships too, so
     * this is off like every other default here that trades recoverability for tidiness.
     */
    public bool $deleteRelations = false;

    /**
     * @var bool Whether to run Craft's garbage collector once a strike finishes.
     */
    public bool $runGcAfterStrike = true;

    /**
     * @var bool Whether strikes may be run from the console.
     *
     * On, because CI and deployment scripts are a legitimate use. Sites that want the control
     * panel to be the only route can turn it off.
     */
    public bool $allowConsoleStrikes = true;

    // ---------------------------------------------------------------------
    // Sweeps
    // ---------------------------------------------------------------------

    /**
     * @var array<string, array<string, mixed>> Per-sweeper configuration, keyed by sweeper handle,
     *                                          including whether each one is switched on.
     *
     * This holds *overrides only*. Anything absent falls back to the sweeper's own
     * `defaultConfig()`, which is what lets a sweeper added in a later release arrive switched on
     * with sensible settings instead of sitting silently disabled because it wasn't in a list
     * written before it existed.
     */
    public array $sweeperConfig = [];

    // ---------------------------------------------------------------------
    // Scheduling (Pro)
    // ---------------------------------------------------------------------

    public bool $scheduleEnabled = false;

    /**
     * @var string One of the FREQUENCY_* constants.
     */
    public string $scheduleFrequency = self::FREQUENCY_WEEKLY;

    /**
     * @var int Hour of day, 0–23, in the system timezone.
     */
    public int $scheduleHour = 3;

    /**
     * @var int Day of week for weekly sweeps: 1 (Monday) – 7 (Sunday), matching ISO-8601.
     */
    public int $scheduleWeekday = 7;

    /**
     * @var int Day of month for monthly sweeps, 1–28.
     *
     * Capped at 28 deliberately: "the 31st" silently skips February and two other months a year,
     * which is a bug report waiting to happen.
     */
    public int $scheduleDayOfMonth = 1;

    /**
     * @var string How due sweeps get started — `cron` (a scheduled console command) or `web` (a
     *             piggyback on control panel requests).
     */
    public string $scheduleTrigger = self::TRIGGER_CRON;

    // ---------------------------------------------------------------------
    // Notifications (Pro)
    // ---------------------------------------------------------------------

    public bool $notifyEnabled = false;

    /**
     * @var string[] Email addresses to notify. Supports environment variables.
     */
    public array $notifyRecipients = [];

    /**
     * @var string When to send: after every run, only when a run actually removed something, or
     *             only when a run hit an error.
     */
    public string $notifyOn = self::NOTIFY_CHANGES;

    /**
     * @var bool Whether to email a notification after a *strike* as well as a sweep.
     *
     * On when notifications are on at all: a bulk deletion is precisely the event the rest of
     * the team wants to hear about.
     */
    public bool $notifyOnStrike = true;

    // ---------------------------------------------------------------------
    // Housekeeping
    // ---------------------------------------------------------------------

    /**
     * @var int How many runs to keep in the ledger. Older ones are pruned after each run.
     */
    public int $retainRuns = 100;

    /**
     * @var string Minimum level for Nuke's own log file.
     *
     * `info` rather than `warning`, unlike the read-only plugins in this family. The log is the
     * audit trail — a successful deletion is exactly the thing worth recording.
     */
    public string $logLevel = 'info';

    public function rules(): array
    {
        return [
            [['maxElementsPerStrike'], 'integer', 'min' => 0, 'max' => 10000000],
            [['batchSize'], 'integer', 'min' => 1, 'max' => 5000],
            [['retainRuns'], 'integer', 'min' => 1, 'max' => 10000],
            [['scheduleHour'], 'integer', 'min' => 0, 'max' => 23],
            [['scheduleWeekday'], 'integer', 'min' => 1, 'max' => 7],
            [['scheduleDayOfMonth'], 'integer', 'min' => 1, 'max' => 28],
            [
                ['scheduleFrequency'],
                'in',
                'range' => [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY],
            ],
            [['scheduleTrigger'], 'in', 'range' => [self::TRIGGER_CRON, self::TRIGGER_WEB]],
            [['notifyOn'], 'in', 'range' => [self::NOTIFY_ALWAYS, self::NOTIFY_CHANGES, self::NOTIFY_PROBLEMS]],
            [['confirmationPhrase'], 'validateConfirmationPhrase'],
            [['notifyRecipients'], 'validateRecipients'],
        ];
    }

    /**
     * An empty phrase with confirmation switched on would arm every strike on an empty input —
     * i.e. on a stray Enter. Reject it rather than silently treating it as "confirmation off".
     */
    public function validateConfirmationPhrase(string $attribute): void
    {
        if ($this->requireConfirmation && trim($this->confirmationPhrase) === '') {
            $this->addError($attribute, Craft::t('nuke', 'A confirmation phrase is needed while confirmation is required.'));
        }
    }

    /**
     * Recipients are validated only when notifications are actually on, and only for entries that
     * aren't environment variables — `$NUKE_REPORT_EMAIL` is a perfectly good value that no email
     * validator will accept.
     */
    public function validateRecipients(string $attribute): void
    {
        if (!$this->notifyEnabled) {
            return;
        }

        foreach ($this->notifyRecipients as $recipient) {
            $recipient = trim((string)$recipient);

            if ($recipient === '' || str_starts_with($recipient, '$')) {
                continue;
            }

            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, Craft::t('nuke', '“{value}” is not a valid email address.', [
                    'value' => $recipient,
                ]));
            }
        }
    }

    /**
     * Recipients with any environment variables resolved, and blanks dropped.
     *
     * @return string[]
     */
    public function resolvedRecipients(): array
    {
        $resolved = [];

        foreach ($this->notifyRecipients as $recipient) {
            $value = trim((string)App::parseEnv($recipient));

            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $resolved[] = $value;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * The phrase an operator has to type to arm a strike against the given scope.
     */
    public function confirmationPhraseFor(string $scopeLabel): string
    {
        $phrase = trim($this->confirmationPhrase);

        if ($phrase === '') {
            return 'NUKE';
        }

        return str_replace('{scope}', $scopeLabel, $phrase);
    }

    /**
     * Whether the named scope has been placed off limits.
     *
     * Compared case-insensitively, because the setting is typed by hand and a handle that differs
     * only in case is not a different section.
     */
    public function isProtected(?string $handle): bool
    {
        if ($handle === null || $handle === '') {
            return false;
        }

        foreach ($this->protectedScopes as $protected) {
            if (strcasecmp(trim((string)$protected), $handle) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configuration for one sweeper, with the sweeper's own defaults underneath any overrides.
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function configFor(string $handle, array $defaults = []): array
    {
        return array_merge($defaults, $this->sweeperConfig[$handle] ?? []);
    }
}
