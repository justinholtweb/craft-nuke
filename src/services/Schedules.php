<?php

namespace justinholtweb\nuke\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use DateTime;
use DateTimeImmutable;
use justinholtweb\nuke\helpers\Cadence;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\Plugin;
use justinholtweb\nuke\queue\SweepJob;
use justinholtweb\nuke\records\RunRecord;

/**
 * Decides when a scheduled sweep is owed, and queues it.
 *
 * "When did it last run?" is answered from the run ledger rather than from a stored timestamp,
 * so there is one source of truth and no way for the two to disagree after a database restore.
 */
class Schedules extends Component
{
    /**
     * Queues a sweep if one is due, and reports whether it did.
     *
     * Safe to call on every request. The cache key is claimed with `add()`, which only succeeds
     * for the first caller — so two simultaneous control panel requests that both find a sweep
     * due will queue exactly one job between them.
     */
    public function queueIfDue(): bool
    {
        if (!$this->isDue()) {
            return false;
        }

        $occurrence = $this->lastOccurrence();
        $key = 'nuke.scheduledSweep.' . $occurrence->getTimestamp();

        if (!Craft::$app->getCache()->add($key, true, 60 * 60 * 24 * 2)) {
            return false;
        }

        Craft::$app->getQueue()->push(new SweepJob(['scheduled' => true]));

        Craft::info(
            'Queued the scheduled sweep for ' . $occurrence->format('Y-m-d H:i'),
            Plugin::LOG_CATEGORY,
        );

        return true;
    }

    public function isDue(): bool
    {
        $settings = $this->settings();

        if (!Plugin::getInstance()->isPro() || !$settings->scheduleEnabled) {
            return false;
        }

        return Cadence::isDue(
            $settings->scheduleFrequency,
            $settings->scheduleHour,
            $settings->scheduleWeekday,
            $settings->scheduleDayOfMonth,
            $this->lastRunAt(),
            $this->now(),
        );
    }

    public function lastOccurrence(): DateTimeImmutable
    {
        $settings = $this->settings();

        return Cadence::lastOccurrence(
            $settings->scheduleFrequency,
            $settings->scheduleHour,
            $settings->scheduleWeekday,
            $settings->scheduleDayOfMonth,
            $this->now(),
        );
    }

    public function nextOccurrence(): DateTimeImmutable
    {
        $settings = $this->settings();

        return Cadence::nextOccurrence(
            $settings->scheduleFrequency,
            $settings->scheduleHour,
            $settings->scheduleWeekday,
            $settings->scheduleDayOfMonth,
            $this->now(),
        );
    }

    /**
     * When a scheduled sweep last ran, or null if none ever has.
     */
    public function lastRunAt(): ?DateTime
    {
        $value = (new Query())
            ->select(['dateCreated'])
            ->from(RunRecord::tableName())
            ->where(['type' => RunRecord::TYPE_SWEEP, 'scheduled' => true])
            ->orderBy(['id' => SORT_DESC])
            ->scalar();

        if (!$value) {
            return null;
        }

        // Craft stores datetimes in UTC. Reading them back in the system timezone is what makes
        // "3am" mean 3am to the person who configured it.
        return new DateTime((string)$value, new \DateTimeZone('UTC'));
    }

    /**
     * A description of the schedule for the settings screen.
     */
    public function describe(): string
    {
        $settings = $this->settings();

        if (!$settings->scheduleEnabled) {
            return Craft::t('nuke', 'No sweeps are scheduled.');
        }

        $time = sprintf('%02d:00', $settings->scheduleHour);

        return match ($settings->scheduleFrequency) {
            Settings::FREQUENCY_DAILY => Craft::t('nuke', 'Every day at {time}.', ['time' => $time]),
            Settings::FREQUENCY_MONTHLY => Craft::t('nuke', 'On day {day} of each month at {time}.', [
                'day' => $settings->scheduleDayOfMonth,
                'time' => $time,
            ]),
            default => Craft::t('nuke', 'Every {weekday} at {time}.', [
                'weekday' => $this->weekdayName($settings->scheduleWeekday),
                'time' => $time,
            ]),
        };
    }

    private function weekdayName(int $weekday): string
    {
        // ISO-8601 day numbers, and a fixed reference Monday to name them from — Craft's own
        // locale data would be nicer but would need a site to be initialised, and this is read
        // from the console too.
        $monday = new DateTime('2024-01-01');
        $monday->modify('+' . ($weekday - 1) . ' days');

        return $monday->format('l');
    }

    private function now(): DateTime
    {
        return new DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
