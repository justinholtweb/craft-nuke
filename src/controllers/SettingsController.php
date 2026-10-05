<?php

namespace justinholtweb\nuke\controllers;

use Craft;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\services\ProjectConfig;
use craft\web\Controller;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\Plugin;
use yii\web\Response;

/**
 * Nuke's settings, split across three screens because one screen holding guardrails, twenty-three
 * sweeper configurations and a schedule is a screen nobody reads.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Admins can always read the settings. Saving writes project config, so actionSave()
        // also needs allowAdminChanges; with it off the screens render read-only.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('nuke/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
            'isPro' => Plugin::getInstance()->isPro(),
            'scopes' => Plugin::getInstance()->scopes->available(),
        ]);
    }

    public function actionSweepers(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('nuke/settings/sweepers', [
            'settings' => $plugin->getSettings(),
            'byGroup' => $plugin->sweepers->byGroup(),
            'groupLabels' => $plugin->sweepers->groupLabels(),
            'registry' => $plugin->sweepers,
        ]);
    }

    public function actionSchedule(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('nuke/settings/schedule', [
            'settings' => $plugin->getSettings(),
            'isPro' => $plugin->isPro(),
            'schedules' => $plugin->schedules,
        ]);
    }

    /**
     * Saves whichever screen posted.
     *
     * Each screen posts only its own fields, and the ones it doesn't post keep their current
     * values. Loading the whole settings model from a partial POST would silently reset every
     * setting the screen doesn't show — the classic way a multi-screen settings form eats a
     * configuration.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        // Start from what project config holds, not getSettings(): that carries config/nuke.php
        // overrides, which belong in that file and would otherwise be copied into project config.
        $stored = Craft::$app->getProjectConfig()->get(ProjectConfig::PATH_PLUGINS . '.' . $plugin->handle . '.settings') ?? [];
        $settings = new Settings(ProjectConfigHelper::unpackAssociativeArrays($stored));
        $posted = $this->request->getBodyParam('settings', []);

        if (!is_array($posted)) {
            $posted = [];
        }

        foreach ($posted as $name => $value) {
            if (!$settings->hasProperty($name)) {
                continue;
            }

            $settings->$name = $this->cast($settings, $name, $value);
        }

        if ($this->request->getBodyParam('sweeperConfig') !== null) {
            $settings->sweeperConfig = $this->sweeperConfig();
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('nuke', 'Couldn’t save settings.'));

            return $this->renderTemplate('nuke/settings/index', [
                'settings' => $settings,
                'isPro' => $plugin->isPro(),
                'scopes' => $plugin->scopes->available(),
            ]);
        }

        $this->setSuccessFlash(Craft::t('nuke', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Coerces a posted value to the type the settings model declares.
     *
     * Everything arrives from a form as a string; assigning `"0"` to a typed `bool` property is a
     * TypeError, and assigning it without the cast would make an unchecked box mean `true`.
     *
     * @param mixed $value
     */
    private function cast(Settings $settings, string $name, mixed $value): mixed
    {
        $current = $settings->$name;

        if (is_bool($current)) {
            return (bool)$value;
        }

        if (is_int($current)) {
            return (int)$value;
        }

        if (is_array($current)) {
            if (is_string($value)) {
                // Textareas of one-per-line values: protected scopes, notification recipients.
                $value = preg_split('/[\r\n,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }

            return array_values(array_filter(array_map('trim', (array)$value), fn($v) => $v !== ''));
        }

        return is_scalar($value) ? (string)$value : $current;
    }

    /**
     * Per-sweeper configuration, filtered to keys each sweeper actually declares.
     *
     * A sweeper's config is written into project config, so accepting arbitrary keys from a form
     * post would let anyone with the settings screen write anything they liked into it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sweeperConfig(): array
    {
        $posted = (array)$this->request->getBodyParam('sweeperConfig', []);
        $registry = Plugin::getInstance()->sweepers;
        $config = [];

        foreach ($registry->all() as $handle => $sweeper) {
            $defaults = $sweeper->defaultConfig();
            $values = (array)($posted[$handle] ?? []);
            $clean = [];

            // `enabled` is not in configFields() — it is rendered as the group's own switch — so
            // it is read separately rather than being lost.
            $clean['enabled'] = (bool)($values['enabled'] ?? false);

            foreach ($sweeper->configFields() as $field) {
                $name = $field['name'];

                if (!array_key_exists($name, $values)) {
                    continue;
                }

                $clean[$name] = $this->castField($field, $values[$name], $defaults[$name] ?? null);
            }

            $config[$handle] = $clean;
        }

        return $config;
    }

    /**
     * @param array{name: string, type: string, min?: int, max?: int} $field
     */
    private function castField(array $field, mixed $value, mixed $default): mixed
    {
        return match ($field['type']) {
            'boolean' => (bool)$value,
            'number', 'days' => max(
                (int)($field['min'] ?? 0),
                min((int)($field['max'] ?? PHP_INT_MAX), (int)$value),
            ),
            default => is_scalar($value) ? (string)$value : $default,
        };
    }
}
