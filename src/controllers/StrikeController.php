<?php

namespace justinholtweb\nuke\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\nuke\models\Target;
use justinholtweb\nuke\Plugin;
use justinholtweb\nuke\queue\StrikeJob;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The strike screen.
 *
 * Three actions, and the order they run in is the guardrail: build a target, preview it, and only
 * then fire. `actionFire()` previews again server-side rather than trusting the numbers the
 * browser was shown — the form could have been sitting open for an hour, and the check is one
 * query.
 */
class StrikeController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_STRIKE);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('nuke/strike/index', [
            'scopes' => $plugin->scopes->available(),
            'target' => new Target(),
            'settings' => $plugin->getSettings(),
            'isPro' => $plugin->isPro(),
            'canHardDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_HARD_DELETE),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Returns the blast radius for a target, as JSON, for the preview panel.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $target = $this->target();
        $plugin = Plugin::getInstance();
        $radius = $plugin->detonator->preview($target);

        return $this->asJson([
            'html' => $this->getView()->renderTemplate('nuke/strike/_radius', [
                'radius' => $radius,
                'target' => $target,
                'settings' => $plugin->getSettings(),
                'confirmationPhrase' => $plugin->getSettings()->confirmationPhraseFor(
                    $plugin->scopes->confirmationLabel($target),
                ),
            ], $this->getView()::TEMPLATE_MODE_CP),
            'canFire' => $radius->canFire(),
            'total' => $radius->total(),
        ]);
    }

    /**
     * Runs the strike.
     */
    public function actionFire(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $target = $this->target();

        if ($target->hardDelete && !Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_HARD_DELETE)) {
            throw new ForbiddenHttpException('You are not permitted to delete permanently.');
        }

        // Previewed again here, server-side. The browser's copy is a suggestion; this is the
        // check, and it is the same code path the deletion itself will take.
        $radius = $plugin->detonator->preview($target);

        if (!$radius->canFire()) {
            return $this->failure(
                $radius->errors[0] ?? Craft::t('nuke', 'Nothing matched, so nothing was deleted.'),
                $target,
            );
        }

        if ($settings->requireConfirmation) {
            $expected = $settings->confirmationPhraseFor($plugin->scopes->confirmationLabel($target));
            $given = trim((string)$this->request->getBodyParam('confirmation', ''));

            if ($given !== $expected) {
                return $this->failure(
                    Craft::t('nuke', 'Type “{phrase}” to confirm. Nothing was deleted.', ['phrase' => $expected]),
                    $target,
                );
            }
        }

        if ($target->queue) {
            Craft::$app->getQueue()->push(new StrikeJob([
                'target' => $target->toArray(),
            ]));

            $this->setSuccessFlash(Craft::t('nuke', 'The strike is queued.'));

            return $this->redirect('nuke/runs');
        }

        try {
            ['runId' => $runId] = $plugin->detonator->launch($target, $radius);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage(), $target);
        }

        $this->setSuccessFlash(Craft::t('nuke', 'Done.'));

        return $this->redirect("nuke/runs/$runId");
    }

    /**
     * Builds a target from the posted form.
     */
    private function target(): Target
    {
        $request = $this->request;
        $target = new Target();

        $target->scope = (string)$request->getBodyParam('scope', 'entries');
        $target->sourceIds = $this->idList($request->getBodyParam('sourceIds'));
        $target->typeIds = $this->idList($request->getBodyParam('typeIds'));
        $target->siteIds = $this->idList($request->getBodyParam('siteIds'));
        $target->elementIds = $this->idList($request->getBodyParam('elementIds'));

        $target->status = (string)$request->getBodyParam('status', Target::STATUS_ANY);
        $target->updatedBefore = $this->nullable($request->getBodyParam('updatedBefore'));
        $target->createdBefore = $this->nullable($request->getBodyParam('createdBefore'));
        $target->search = $this->nullable($request->getBodyParam('search'));
        $target->note = $this->nullable($request->getBodyParam('note'));

        $limit = $request->getBodyParam('limit');
        $target->limit = ($limit === null || $limit === '') ? null : max(1, (int)$limit);

        $target->purgeHistory = (bool)$request->getBodyParam('purgeHistory', false);
        $target->includeTrashed = (bool)$request->getBodyParam('includeTrashed', false);
        $target->deleteRelations = (bool)$request->getBodyParam('deleteRelations', false);
        $target->hardDelete = (bool)$request->getBodyParam('hardDelete', false);
        $target->backup = (bool)$request->getBodyParam('backup', true);
        $target->runGc = (bool)$request->getBodyParam('runGc', true);
        $target->queue = (bool)$request->getBodyParam('queue', false);

        if (!Plugin::getInstance()->scopes->has($target->scope)) {
            throw new BadRequestHttpException("Unknown scope “{$target->scope}”.");
        }

        return $target;
    }

    /**
     * @return int[]
     */
    private function idList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === '*') {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_filter(array_map('intval', (array)$value)));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function failure(string $message, Target $target): Response
    {
        $this->setFailFlash($message);

        return $this->renderTemplate('nuke/strike/index', [
            'scopes' => Plugin::getInstance()->scopes->available(),
            'target' => $target,
            'settings' => Plugin::getInstance()->getSettings(),
            'isPro' => Plugin::getInstance()->isPro(),
            'canHardDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_HARD_DELETE),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }
}
