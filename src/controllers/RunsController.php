<?php

namespace justinholtweb\nuke\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\nuke\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The run ledger.
 */
class RunsController extends Controller
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
        $type = $this->request->getQueryParam('type');

        return $this->renderTemplate('nuke/runs/index', [
            'runs' => Plugin::getInstance()->runs->recent(100, $type ?: null),
            'type' => $type,
        ]);
    }

    public function actionDetail(int $id): Response
    {
        $plugin = Plugin::getInstance();
        $run = $plugin->runs->get($id);

        if ($run === null) {
            throw new NotFoundHttpException('No such run.');
        }

        return $this->renderTemplate('nuke/runs/detail', [
            'run' => $run,
            'backupExists' => $plugin->backups->exists($run->backupPath),
            'groupLabels' => $plugin->sweepers->groupLabels(),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SWEEP);

        Plugin::getInstance()->runs->delete((int)$this->request->getRequiredBodyParam('id'));

        $this->setSuccessFlash(Craft::t('nuke', 'Run deleted from the ledger.'));

        return $this->redirect('nuke/runs');
    }
}
