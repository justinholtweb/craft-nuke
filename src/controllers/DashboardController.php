<?php

namespace justinholtweb\nuke\controllers;

use craft\web\Controller;
use justinholtweb\nuke\Plugin;
use yii\web\Response;

/**
 * The overview screen: what has been deleted lately, what is scheduled, and what a sweep would
 * find if you ran one right now.
 */
class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('nuke/dashboard/index', [
            'stats' => $plugin->runs->stats(),
            'recent' => $plugin->runs->recent(8),
            'schedules' => $plugin->schedules,
            'sweepers' => $plugin->sweepers,
            'enabledSweepers' => $plugin->sweepers->enabled(),
            'isPro' => $plugin->isPro(),
            'settings' => $plugin->getSettings(),
        ]);
    }
}
