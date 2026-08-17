<?php

namespace justinholtweb\nuke\sweepers;

use Craft;
use justinholtweb\nuke\helpers\Bytes;
use justinholtweb\nuke\models\SweepResult;

/**
 * Clears compiled templates and generated classes.
 *
 * Twig writes a PHP file per template per version and never removes the old ones, so a site
 * deployed weekly for two years has a hundred copies of every template. Craft's generated element
 * classes behave the same way.
 *
 * Both regenerate on the next request that needs them, at the cost of one slow page load.
 */
class CompiledFilesSweeper extends BaseSweeper
{
    public static function handle(): string
    {
        return 'compiled';
    }

    public function label(): string
    {
        return Craft::t('nuke', 'Compiled templates');
    }

    public function description(): string
    {
        return Craft::t('nuke', 'Clears compiled Twig templates and generated classes older than the window. Twig keeps a copy of every version of every template it has ever compiled and removes none of them.');
    }

    public function group(): string
    {
        return self::GROUP_FILES;
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'olderThanDays' => 30,
        ];
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'olderThanDays',
                'label' => Craft::t('nuke', 'Older than'),
                'type' => 'days',
                'instructions' => Craft::t('nuke', 'Anything compiled since your last deploy is in active use — keep the window comfortably longer than your deploy interval.'),
                'min' => 1,
                'max' => 3650,
            ],
        ];
    }

    protected function execute(SweepResult $result, array $config, bool $dryRun): void
    {
        $paths = Craft::$app->getPath();
        $cutoff = $this->cutoff(max(1, $this->days($config)));

        foreach ([$paths->getCompiledTemplatesPath(false), $paths->getCompiledClassesPath(false)] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $this->sweepFiles($result, $this->agedTreeFiles($dir, $cutoff), $dryRun);
        }

        if ($result->found > 0) {
            $result->message = Craft::t('nuke', '{size} of compiled files.', [
                'size' => Bytes::format($result->bytes),
            ]);
        }
    }
}
