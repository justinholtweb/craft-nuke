<?php

namespace justinholtweb\nuke\helpers;

use Craft;

/**
 * Formats byte counts.
 *
 * `craft\helpers\FileHelper` has no such method — the formatting lives on the application's
 * formatter component, which is a mouthful to reach for from twenty sweepers and easy to get
 * subtly wrong (Yii's own `asShortSize` returns lower-case units; Craft's override upper-cases
 * them, and only Craft's is what the rest of the control panel shows).
 */
final class Bytes
{
    public static function format(int|float|null $bytes): string
    {
        return Craft::$app->getFormatter()->asShortSize((int)($bytes ?? 0), 1);
    }
}
