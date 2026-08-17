<?php

/**
 * Bootstrap for the unit suite.
 *
 * These run against plain PHP — no Craft application, no database. What they cover is the
 * part of Nuke that is deliberately pure: parsing retention windows, matching filenames
 * against sweep patterns, formatting yields, and deciding whether a scheduled sweep is due.
 * Everything that actually deletes something is exercised against the plugin-testing
 * harness instead, as described in tests/README.md.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
