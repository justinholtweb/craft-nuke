<?php

namespace justinholtweb\nuke\tests\unit;

use DateTimeImmutable;
use justinholtweb\nuke\helpers\Cadence;
use PHPUnit\Framework\TestCase;

/**
 * The schedule arithmetic.
 *
 * All of this is pure, and all of it is the kind of thing that looks obviously right and is
 * quietly wrong on one day in seven or one day in thirty. The cases that matter are the boundary
 * ones: the hour the schedule fires, the day it rolls over, and the month it wraps.
 */
class CadenceTest extends TestCase
{
    private function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    public function testDailyBeforeTheHourUsesYesterday(): void
    {
        $last = Cadence::lastOccurrence(Cadence::DAILY, 3, 1, 1, $this->at('2026-08-17 02:59:00'));

        self::assertSame('2026-08-16 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testDailyOnTheHourUsesToday(): void
    {
        $last = Cadence::lastOccurrence(Cadence::DAILY, 3, 1, 1, $this->at('2026-08-17 03:00:00'));

        self::assertSame('2026-08-17 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testWeeklyWalksBackToTheChosenWeekday(): void
    {
        // 2026-08-17 is a Monday. Asking for Sunday (7) must reach the day before, not six days on.
        $last = Cadence::lastOccurrence(Cadence::WEEKLY, 3, 7, 1, $this->at('2026-08-17 10:00:00'));

        self::assertSame('2026-08-16 03:00:00', $last->format('Y-m-d H:i:s'));
        self::assertSame('7', $last->format('N'));
    }

    public function testWeeklyOnTheChosenWeekdayBeforeTheHourGoesBackAFullWeek(): void
    {
        $last = Cadence::lastOccurrence(Cadence::WEEKLY, 3, 1, 1, $this->at('2026-08-17 01:00:00'));

        self::assertSame('2026-08-10 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testMonthlyBeforeTheDayUsesLastMonth(): void
    {
        $last = Cadence::lastOccurrence(Cadence::MONTHLY, 3, 1, 15, $this->at('2026-08-14 23:59:00'));

        self::assertSame('2026-07-15 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testMonthlyWrapsIntoFebruaryWithoutOverflowing(): void
    {
        // The classic `-1 month` bug: from 31 March it lands on 3 March. Capping the setting at
        // 28 and stepping via "first day of last month" is what stops it.
        $last = Cadence::lastOccurrence(Cadence::MONTHLY, 3, 1, 28, $this->at('2026-03-27 12:00:00'));

        self::assertSame('2026-02-28 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testDayOfMonthIsClampedTo28(): void
    {
        $last = Cadence::lastOccurrence(Cadence::MONTHLY, 3, 1, 31, $this->at('2026-08-30 12:00:00'));

        self::assertSame('2026-08-28 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testHourIsClamped(): void
    {
        $last = Cadence::lastOccurrence(Cadence::DAILY, 99, 1, 1, $this->at('2026-08-17 23:59:00'));

        self::assertSame('23', $last->format('H'));
    }

    public function testNextOccurrenceIsAlwaysAhead(): void
    {
        $now = $this->at('2026-08-17 10:00:00');

        foreach ([Cadence::DAILY, Cadence::WEEKLY, Cadence::MONTHLY] as $frequency) {
            self::assertGreaterThan(
                $now,
                Cadence::nextOccurrence($frequency, 3, 7, 1, $now),
                "next $frequency occurrence should be in the future",
            );
        }
    }

    public function testNeverRunIsDue(): void
    {
        self::assertTrue(Cadence::isDue(Cadence::DAILY, 3, 1, 1, null, $this->at('2026-08-17 10:00:00')));
    }

    public function testRunAfterTheLastOccurrenceIsNotDue(): void
    {
        $due = Cadence::isDue(
            Cadence::DAILY,
            3,
            1,
            1,
            $this->at('2026-08-17 03:05:00'),
            $this->at('2026-08-17 10:00:00'),
        );

        self::assertFalse($due);
    }

    public function testRunBeforeTheLastOccurrenceIsDue(): void
    {
        $due = Cadence::isDue(
            Cadence::DAILY,
            3,
            1,
            1,
            $this->at('2026-08-16 03:05:00'),
            $this->at('2026-08-17 10:00:00'),
        );

        self::assertTrue($due);
    }

    /**
     * The reason occurrences exist at all: a schedule that measured "has 24 hours passed?" would
     * drift a few minutes later every day and eventually fire in the afternoon.
     */
    public function testARunSlightlyLateDoesNotPushTheNextOneLate(): void
    {
        $lateRun = $this->at('2026-08-17 03:47:00');

        self::assertFalse(Cadence::isDue(Cadence::DAILY, 3, 1, 1, $lateRun, $this->at('2026-08-18 02:00:00')));
        self::assertTrue(Cadence::isDue(Cadence::DAILY, 3, 1, 1, $lateRun, $this->at('2026-08-18 03:00:00')));
    }
}
