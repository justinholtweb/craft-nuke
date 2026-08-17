<?php

namespace justinholtweb\nuke\tests\unit;

use justinholtweb\nuke\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the settings model that decide whether something may be deleted.
 *
 * These are three small methods, and each of them is the last thing standing between a form post
 * and a bulk deletion, so they are worth pinning down.
 *
 * The `rules()` are exercised in the harness rather than here: their messages go through
 * `Craft::t()`, which needs a booted Craft application, and booting one to check a validator
 * would make this suite something other than a unit suite.
 */
class SettingsTest extends TestCase
{
    public function testConfirmationPhraseInterpolatesTheScope(): void
    {
        $settings = new Settings();
        $settings->confirmationPhrase = '{scope}';

        self::assertSame('news', $settings->confirmationPhraseFor('news'));
    }

    public function testConfirmationPhraseCanBeALiteral(): void
    {
        $settings = new Settings();
        $settings->confirmationPhrase = 'DELETE FOREVER';

        self::assertSame('DELETE FOREVER', $settings->confirmationPhraseFor('news'));
    }

    /**
     * An empty phrase would arm every strike on an empty input — i.e. on a stray Enter. The
     * validator rejects it, but the accessor must not produce one either, in case the value
     * arrived from config/nuke.php where no validator runs.
     */
    public function testEmptyConfirmationPhraseFallsBackRatherThanMatchingNothing(): void
    {
        $settings = new Settings();
        $settings->confirmationPhrase = '   ';

        self::assertSame('NUKE', $settings->confirmationPhraseFor('news'));
    }

    public function testProtectedScopesAreMatchedCaseInsensitively(): void
    {
        $settings = new Settings();
        $settings->protectedScopes = ['News', ' products '];

        self::assertTrue($settings->isProtected('news'));
        self::assertTrue($settings->isProtected('NEWS'));
        self::assertTrue($settings->isProtected('products'));
        self::assertFalse($settings->isProtected('blog'));
    }

    public function testNothingIsProtectedByAnEmptyHandle(): void
    {
        $settings = new Settings();
        $settings->protectedScopes = ['news'];

        self::assertFalse($settings->isProtected(null));
        self::assertFalse($settings->isProtected(''));
    }

    public function testSweeperConfigOverridesDefaultsWithoutDroppingThem(): void
    {
        $settings = new Settings();
        $settings->sweeperConfig = ['backups' => ['olderThanDays' => 90]];

        $config = $settings->configFor('backups', [
            'enabled' => true,
            'olderThanDays' => 30,
            'keepNewest' => 5,
        ]);

        self::assertSame(90, $config['olderThanDays']);
        self::assertSame(5, $config['keepNewest'], 'untouched keys keep the sweeper’s own default');
        self::assertTrue($config['enabled']);
    }

    /**
     * The whole reason the setting holds overrides only: a sweeper added in a later release has
     * no entry here, and must still arrive with its own defaults rather than an empty array.
     */
    public function testAnUnknownSweeperGetsItsDefaults(): void
    {
        $settings = new Settings();

        self::assertSame(
            ['enabled' => true, 'olderThanDays' => 7],
            $settings->configFor('somethingAddedLater', ['enabled' => true, 'olderThanDays' => 7]),
        );
    }
}
