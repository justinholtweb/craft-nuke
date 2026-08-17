<?php

namespace justinholtweb\nuke\tests\unit;

use justinholtweb\nuke\models\Target;
use PHPUnit\Framework\TestCase;

/**
 * How a target reads the values a form and a console argument hand it.
 *
 * Both of those arrive as strings, and both of them are describing which content to delete, so
 * "-2 years" parsing as something other than two years ago is not a cosmetic problem.
 */
class TargetTest extends TestCase
{
    public function testIdsAreNormalisedFromStrings(): void
    {
        $target = new Target();
        $target->sourceIds = ['3', '7', 12];

        self::assertSame([3, 7, 12], $target->ids('sourceIds'));
    }

    /**
     * Craft's checkbox groups post a hidden empty value alongside the real ones, so "" has to
     * disappear rather than become source id 0 — which would match nothing and silently turn
     * "delete the News section" into "delete nothing".
     */
    public function testEmptyAndZeroIdsAreDropped(): void
    {
        $target = new Target();
        $target->sourceIds = ['', '0', 'nonsense', '5'];

        self::assertSame([5], $target->ids('sourceIds'));
    }

    public function testAbsoluteDatesParse(): void
    {
        $target = new Target();
        $target->updatedBefore = '2020-01-01';

        self::assertSame('2020-01-01', $target->dateFor('updatedBefore')?->format('Y-m-d'));
    }

    /**
     * `DateTimeHelper::toDateTime()` rejects relative strings, which is why the accessor falls
     * through to `DateTime` itself. Without that, `-2 years` would read as "no filter" and the
     * strike would match every entry in the section.
     */
    public function testRelativeDatesParse(): void
    {
        $target = new Target();
        $target->updatedBefore = '-2 years';

        $date = $target->dateFor('updatedBefore');

        self::assertNotNull($date);
        self::assertSame((int)date('Y') - 2, (int)$date->format('Y'));
    }

    public function testBlankDatesAreNull(): void
    {
        $target = new Target();
        $target->updatedBefore = '   ';
        $target->createdBefore = null;

        self::assertNull($target->dateFor('updatedBefore'));
        self::assertNull($target->dateFor('createdBefore'));
    }

    public function testUnparseableDatesAreNullRatherThanNow(): void
    {
        $target = new Target();
        $target->updatedBefore = 'last thursdayish';

        self::assertNull($target->dateFor('updatedBefore'));
    }

    /**
     * The defaults are the timid ones. If any of these flip, a strike created from an empty form
     * becomes more destructive than the operator asked for.
     */
    public function testDefaultsHesitate(): void
    {
        $target = new Target();

        self::assertFalse($target->hardDelete, 'a new target must not delete permanently');
        self::assertFalse($target->purgeHistory, 'a new target must not purge history');
        self::assertFalse($target->deleteRelations, 'a new target must not clear relations');
        self::assertFalse($target->includeTrashed, 'the trash is somewhere things were put on purpose');
        self::assertTrue($target->backup, 'a new target must back up first');
        self::assertTrue($target->runGc, 'and tidy up after itself');
    }
}
