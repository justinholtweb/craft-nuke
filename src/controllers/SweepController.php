<?php

namespace justinholtweb\nuke\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\nuke\models\SweepResult;
use justinholtweb\nuke\Plugin;
use justinholtweb\nuke\queue\SweepJob;
use justinholtweb\nuke\records\RunRecord;
use yii\web\Response;

/**
 * The sweep screen: what housekeeping would find, and running it.
 */
class SweepController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_SWEEP);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('nuke/sweep/index', [
            'sweepers' => $plugin->sweepers,
            'byGroup' => $plugin->sweepers->byGroup(),
            'groupLabels' => $plugin->sweepers->groupLabels(),
            'settings' => $plugin->getSettings(),
            'isPro' => $plugin->isPro(),
            'lastSweep' => $plugin->runs->latest(RunRecord::TYPE_SWEEP),
        ]);
    }

    /**
     * Runs every selected sweeper in scan mode and returns the report.
     */
    public function actionScan(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $results = Plugin::getInstance()->sweep->scan($this->handles());

        return $this->asJson([
            'html' => $this->getView()->renderTemplate('nuke/sweep/_report', [
                'results' => $results,
                'dryRun' => true,
                'groupLabels' => Plugin::getInstance()->sweepers->groupLabels(),
            ], $this->getView()::TEMPLATE_MODE_CP),
            'found' => array_sum(array_map(fn(SweepResult $r) => $r->found, $results)),
        ]);
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $handles = $this->handles();

        if ((bool)$this->request->getBodyParam('queue', false)) {
            Craft::$app->getQueue()->push(new SweepJob(['handles' => $handles]));
            $this->setSuccessFlash(Craft::t('nuke', 'The sweep is queued.'));

            return $this->redirect('nuke/runs');
        }

        ['runId' => $runId] = Plugin::getInstance()->sweep->run($handles);

        $this->setSuccessFlash(Craft::t('nuke', 'Swept.'));

        return $runId !== null
            ? $this->redirect("nuke/runs/$runId")
            : $this->redirect('nuke/sweep');
    }

    /**
     * The sweepers the form asked for, or null for "everything that's switched on".
     *
     * @return string[]|null
     */
    private function handles(): ?array
    {
        $handles = $this->request->getBodyParam('handles');

        if (!is_array($handles) || $handles === []) {
            return null;
        }

        $registry = Plugin::getInstance()->sweepers;

        return array_values(array_filter(
            array_map('strval', $handles),
            fn(string $handle) => $registry->has($handle),
        ));
    }
}
