<?php

namespace justinholtweb\nuke\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Db;
use justinholtweb\nuke\events\StrikeEvent;
use justinholtweb\nuke\models\BlastRadius;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\models\StrikeOutcome;
use justinholtweb\nuke\models\Target;
use justinholtweb\nuke\Plugin;
use justinholtweb\nuke\records\RunRecord;
use justinholtweb\nuke\targets\ScopeInterface;
use Throwable;
use yii\base\InvalidArgumentException;

/**
 * Runs strikes, and previews them.
 *
 * The preview and the execution share {@see resolveIds()}. That is the design: a preview that
 * builds its own query is a preview of a different deletion, and every "it deleted more than it
 * said it would" bug lives in that gap. What the preview counts is the exact list of IDs the
 * execution will walk.
 *
 * The list is materialised rather than streamed. Ten thousand integers is a rounding error in
 * memory terms, and holding them makes the descendant, relation and collateral counts three
 * ordinary queries instead of three correlated subqueries against a moving target.
 */
class Detonator extends Component
{
    /**
     * @event StrikeEvent Raised before a strike deletes anything. Cancellable via `$isValid`.
     */
    public const EVENT_BEFORE_STRIKE = 'beforeStrike';

    /**
     * @event StrikeEvent Raised once a strike has finished.
     */
    public const EVENT_AFTER_STRIKE = 'afterStrike';

    /** How many rows to put in one `IN (…)` clause. */
    private const CHUNK = 500;

    /** How many matched elements to show the operator. */
    private const SAMPLE_SIZE = 12;

    /** How many referencing elements to list. */
    private const COLLATERAL_SIZE = 25;

    /**
     * Everything the strike would do, without doing any of it.
     */
    public function preview(Target $target): BlastRadius
    {
        $started = microtime(true);
        $radius = new BlastRadius();
        $settings = $this->settings();

        if (!Plugin::getInstance()->scopes->has($target->scope)) {
            $radius->refuse(Craft::t('nuke', 'There is no “{scope}” to delete.', ['scope' => $target->scope]));
            return $radius;
        }

        $scope = Plugin::getInstance()->scopes->get($target->scope);
        $radius->description = $scope->describe($target);

        foreach ($scope->validate($target, $settings) as $error) {
            $radius->refuse($error);
        }

        if ($this->authorsIgnoredBy($scope, $target)) {
            $radius->refuse(Craft::t('nuke', '{things} can’t be filtered by author.', ['things' => $scope->label()]));
        }

        foreach ($scope->warnings($target) as $warning) {
            $radius->warn($warning);
        }

        if (!$target->validate()) {
            foreach ($target->getErrorSummary(true) as $error) {
                $radius->refuse($error);
            }
        }

        if ($radius->errors !== []) {
            $radius->duration = microtime(true) - $started;
            return $radius;
        }

        $ids = $this->resolveIds($target);
        $radius->elements = count($ids);

        if ($settings->maxElementsPerStrike > 0 && $radius->elements > $settings->maxElementsPerStrike) {
            $radius->overLimit = true;
            $radius->refuse(Craft::t('nuke', 'This matches {n} elements, over the ceiling of {max}. Narrow the target, or raise the ceiling in Nuke’s settings.', [
                'n' => $radius->elements,
                'max' => $settings->maxElementsPerStrike,
            ]));
        }

        if ($ids === []) {
            $radius->duration = microtime(true) - $started;
            return $radius;
        }

        $radius->drafts = $this->countHistory($ids, 'draftId');
        $radius->revisions = $this->countHistory($ids, 'revisionId');
        $radius->incomingRelations = $this->countRelations($ids, 'targetId');
        $radius->outgoingRelations = $this->countRelations($ids, 'sourceId');
        $radius->sample = $this->sample($scope, $ids);

        if ($scope->hasStructure($target)) {
            $radius->promotedDescendants = $this->countPromotedDescendants($ids);
        }

        [$radius->collateral, $radius->collateralCount] = $this->collateral($ids);

        if ($scope->elementType() === Asset::class) {
            [$radius->files, $radius->bytes] = $this->assetFootprint($ids);
        }

        foreach ($this->advisoryWarnings($target, $radius) as $warning) {
            $radius->warn($warning);
        }

        $radius->duration = microtime(true) - $started;

        return $radius;
    }

    /**
     * Deletes what the target names.
     *
     * A run is not one transaction. Each batch commits on its own, so a failure two thirds of the
     * way through leaves two thirds deleted and a run record that says so — which is recoverable
     * from a backup — rather than a single transaction big enough to time out, roll back, and
     * leave nobody sure what happened.
     */
    public function fire(Target $target, ?int $runId = null, ?callable $onProgress = null): StrikeOutcome
    {
        $started = microtime(true);
        $outcome = new StrikeOutcome();
        $settings = $this->settings();
        $scope = Plugin::getInstance()->scopes->get($target->scope);

        $event = new StrikeEvent(['target' => $target, 'runId' => $runId]);
        $this->trigger(self::EVENT_BEFORE_STRIKE, $event);

        if (!$event->isValid) {
            $outcome->errors[] = Craft::t('nuke', 'The strike was cancelled by an event handler.');
            $outcome->duration = microtime(true) - $started;
            return $outcome;
        }

        $ids = $this->resolveIds($target);

        if ($ids === []) {
            $outcome->duration = microtime(true) - $started;
            return $outcome;
        }

        $this->log("Strike #{$runId}: {$scope->describe($target)} — " . count($ids) . ' elements, ' .
            ($target->hardDelete ? 'permanent' : 'to trash'));

        if ($target->backup) {
            try {
                $outcome->backupPath = Plugin::getInstance()->backups->take($runId);
            } catch (Throwable $e) {
                // A requested backup that failed is a hard stop. The operator asked for it
                // because of what was about to happen next, so what happens next is nothing.
                $outcome->errors[] = $e->getMessage();
                $outcome->duration = microtime(true) - $started;
                return $outcome;
            }
        }

        // History first, and only while the canonical elements still exist: `drafts.canonicalId`
        // is `ON DELETE CASCADE`, so once the canonical row is gone the rows to count are gone
        // too, and a purge that ran afterwards would truthfully report deleting nothing.
        if ($target->purgeHistory) {
            $outcome->drafts = $this->purgeHistory($ids, 'drafts');
            $outcome->revisions = $this->purgeHistory($ids, 'revisions');
        } else {
            $outcome->drafts = $this->countHistory($ids, 'draftId');
            $outcome->revisions = $this->countHistory($ids, 'revisionId');
        }

        if ($scope->elementType() === Asset::class) {
            [, $outcome->bytes] = $this->assetFootprint($ids);
        }

        $elements = Craft::$app->getElements();
        $batchSize = max(1, $settings->batchSize);
        $processed = 0;
        $total = count($ids);

        foreach (array_chunk($ids, $batchSize) as $chunk) {
            foreach ($this->loadChunk($scope, $target, $chunk) as $element) {
                try {
                    if ($elements->deleteElement($element, $target->hardDelete)) {
                        $outcome->elements++;
                    } else {
                        $outcome->fail(sprintf('%s #%d refused to delete.', $element::displayName(), $element->id));
                    }
                } catch (Throwable $e) {
                    $outcome->fail(sprintf('%s #%d: %s', $element::displayName(), $element->id, $e->getMessage()));
                    Craft::error(sprintf('Strike #%s failed on element #%d: %s', $runId ?? '?', $element->id, $e->getMessage()), Plugin::LOG_CATEGORY);
                }
            }

            $processed += count($chunk);

            if ($onProgress !== null) {
                $onProgress($processed, $total);
            }

            // A run where nearly everything is failing is a run with a systemic problem — a
            // missing table, a broken plugin hook — and grinding through the remaining 40,000 to
            // report the same error 40,000 times helps nobody.
            if ($outcome->failed > 50 && $outcome->failed > $outcome->elements) {
                $outcome->abortedEarly = true;
                $this->log("Strike #{$runId} aborted after {$outcome->failed} failures.", 'error');
                break;
            }
        }

        // Craft puts a cascading foreign key on `relations.sourceId` but *not* on `targetId`, so
        // a hard delete takes the relations these elements pointed out of and leaves every
        // relation pointing at them behind, dangling at an id that no longer exists. Those rows
        // are wrong by definition, so they go whatever the flag says; the flag governs the soft
        // case, where keeping them is what makes a restore restore the relationships.
        if ($target->hardDelete || $target->deleteRelations) {
            $outcome->relations = $this->deleteRelations($ids);
        }

        if ($target->runGc) {
            Craft::$app->getGc()->run(true);
            $outcome->gcRan = true;
        }

        $outcome->duration = microtime(true) - $started;

        $this->log(sprintf(
            'Strike #%s finished: %d elements, %d drafts, %d revisions, %d relations, %d failed, %.1fs',
            $runId ?? '?',
            $outcome->elements,
            $outcome->drafts,
            $outcome->revisions,
            $outcome->relations,
            $outcome->failed,
            $outcome->duration,
        ));

        $this->trigger(self::EVENT_AFTER_STRIKE, new StrikeEvent([
            'target' => $target,
            'outcome' => $outcome,
            'runId' => $runId,
        ]));

        return $outcome;
    }

    /**
     * The whole ceremony around a strike: open a ledger row, fire, close it, tell people.
     *
     * The control panel and the queue job both come through here, so a strike run in the
     * background is recorded identically to one run in a request — including the preview it was
     * approved against, which is the only way the "predicted vs actual" comparison on the run
     * screen means anything.
     *
     * @return array{runId: int, outcome: StrikeOutcome}
     */
    public function launch(Target $target, ?BlastRadius $preview = null, ?callable $onProgress = null): array
    {
        $runs = Plugin::getInstance()->runs;
        $scope = Plugin::getInstance()->scopes->get($target->scope);

        $runId = $runs->open(
            RunRecord::TYPE_STRIKE,
            $scope->describe($target),
            $target->toArray(),
            $preview !== null ? $this->serialisePreview($preview) : null,
            false,
            false,
            $target->note,
        );

        try {
            $outcome = $this->fire($target, $runId, $onProgress);
        } catch (Throwable $e) {
            $runs->markFailed($runId, $e->getMessage());
            Craft::error("Strike #$runId threw: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            throw $e;
        }

        $runs->close(
            $runId,
            [
                'elements' => $outcome->elements,
                'drafts' => $outcome->drafts,
                'revisions' => $outcome->revisions,
                'relations' => $outcome->relations,
                'bytes' => $outcome->bytes,
                'gcRan' => $outcome->gcRan,
                'abortedEarly' => $outcome->abortedEarly,
                'errors' => $outcome->errors,
            ],
            $outcome->total(),
            $outcome->failed,
            $outcome->duration,
            $outcome->backupPath,
            $outcome->errors !== [] && $outcome->total() === 0
                ? RunRecord::STATUS_FAILED
                : RunRecord::STATUS_DONE,
        );

        try {
            Plugin::getInstance()->notifications->strikeFinished($runId, $target, $outcome);
        } catch (Throwable $e) {
            // The deletion happened whatever the mail server thinks.
            Craft::error("Strike notification failed: {$e->getMessage()}", Plugin::LOG_CATEGORY);
        }

        return ['runId' => $runId, 'outcome' => $outcome];
    }

    /**
     * The preview, flattened for the ledger.
     *
     * The samples are dropped: they are element titles, and by the time anybody reads the record
     * those elements are gone, so storing them would be storing a copy of deleted content.
     *
     * @return array<string, mixed>
     */
    private function serialisePreview(BlastRadius $radius): array
    {
        return [
            'total' => $radius->total(),
            'elements' => $radius->elements,
            'drafts' => $radius->drafts,
            'revisions' => $radius->revisions,
            'promotedDescendants' => $radius->promotedDescendants,
            'incomingRelations' => $radius->incomingRelations,
            'outgoingRelations' => $radius->outgoingRelations,
            'collateralCount' => $radius->collateralCount,
            'files' => $radius->files,
            'bytes' => $radius->bytes,
            'description' => $radius->description,
            'warnings' => $radius->warnings,
        ];
    }

    /**
     * The exact element IDs a target matches.
     *
     * @return int[]
     */
    public function resolveIds(Target $target): array
    {
        $scope = Plugin::getInstance()->scopes->get($target->scope);
        $settings = $this->settings();

        // The preview refuses this with a sentence; this is the backstop for the paths that fire
        // without one — a queued job, a direct call — where the alternative is deleting every
        // author's content instead of one author's.
        if ($this->authorsIgnoredBy($scope, $target)) {
            throw new InvalidArgumentException("The “{$target->scope}” scope can’t filter by author.");
        }

        $query = $scope->query($target);

        // One over the ceiling, so the preview can tell "exactly at the limit" from "past it"
        // without counting a set it has already refused to touch.
        if ($settings->maxElementsPerStrike > 0) {
            $cap = $settings->maxElementsPerStrike + 1;
            $existing = $query->limit;
            $query->limit($existing !== null ? min((int)$existing, $cap) : $cap);
        }

        return array_values(array_unique(array_map('intval', $query->ids())));
    }

    /**
     * Loads one batch as real elements.
     *
     * Deletion has to go through `Elements::deleteElement()` rather than a bulk `DELETE`: it is
     * what fires `beforeDelete`/`afterDelete`, invalidates caches, unhooks structure nodes and
     * lets other plugins clean up after themselves. Bulk SQL would be faster and would leave the
     * site subtly broken.
     *
     * @param int[] $chunk
     * @return ElementInterface[]
     */
    private function loadChunk(ScopeInterface $scope, Target $target, array $chunk): array
    {
        $elementType = $scope->elementType();

        return $elementType::find()
            ->id($chunk)
            ->status(null)
            ->trashed($target->includeTrashed ? null : false)
            ->siteId('*')
            ->unique()
            ->limit(null)
            ->all();
    }

    /**
     * Drafts or revisions belonging to the given canonical elements.
     *
     * Counted off `elements.canonicalId` rather than the `drafts` table, so the same query works
     * for every element type and picks up nested drafts too.
     *
     * @param int[] $ids
     */
    private function countHistory(array $ids, string $column): int
    {
        $count = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $count += (int)(new Query())
                ->from(Table::ELEMENTS)
                ->where(['canonicalId' => $chunk])
                ->andWhere(['not', [$column => null]])
                ->count();
        }

        return $count;
    }

    /**
     * Hard-deletes the `drafts` or `revisions` rows for the given canonical elements.
     *
     * Both tables cascade to their `elements` rows, so removing the row here removes the element
     * with it — which is why this counts rows in the owning table rather than elements.
     *
     * @param int[] $ids
     */
    private function purgeHistory(array $ids, string $table): int
    {
        $tableName = $table === 'drafts' ? Table::DRAFTS : Table::REVISIONS;
        $deleted = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $deleted += Db::delete($tableName, ['canonicalId' => $chunk]);
        }

        return $deleted;
    }

    /**
     * @param int[] $ids
     */
    private function countRelations(array $ids, string $column): int
    {
        $count = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $count += (int)(new Query())
                ->from(Table::RELATIONS)
                ->where([$column => $chunk])
                ->count();
        }

        return $count;
    }

    /**
     * @param int[] $ids
     */
    private function deleteRelations(array $ids): int
    {
        $deleted = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $deleted += Db::delete(Table::RELATIONS, ['sourceId' => $chunk]);
            $deleted += Db::delete(Table::RELATIONS, ['targetId' => $chunk]);
        }

        return $deleted;
    }

    /**
     * Descendants that survive the strike and get moved up the tree.
     *
     * Craft re-parents a structure element's children before deleting it, so they are not lost —
     * they end up wherever their parent used to sit. Descendants that the target matched anyway
     * are excluded, since those are being deleted rather than promoted.
     *
     * @param int[] $ids
     */
    private function countPromotedDescendants(array $ids): int
    {
        $lookup = array_flip($ids);
        $descendants = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = (new Query())
                ->select(['child.elementId'])
                ->from(['parent' => Table::STRUCTUREELEMENTS])
                ->innerJoin(
                    ['child' => Table::STRUCTUREELEMENTS],
                    '[[child.structureId]] = [[parent.structureId]] AND [[child.lft]] > [[parent.lft]] AND [[child.rgt]] < [[parent.rgt]]'
                )
                ->where(['parent.elementId' => $chunk])
                ->column();

            foreach ($rows as $elementId) {
                $elementId = (int)$elementId;

                if (!isset($lookup[$elementId])) {
                    $descendants[$elementId] = true;
                }
            }
        }

        return count($descendants);
    }

    /**
     * Elements that are not being deleted but hold a relation to something that is.
     *
     * @param int[] $ids
     * @return array{0: array<int, array{id: int, title: string, type: string, url: string|null, count: int}>, 1: int}
     */
    private function collateral(array $ids): array
    {
        $lookup = array_flip($ids);
        $counts = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = (new Query())
                ->select(['sourceId'])
                ->from(Table::RELATIONS)
                ->where(['targetId' => $chunk])
                ->column();

            foreach ($rows as $sourceId) {
                $sourceId = (int)$sourceId;

                if (isset($lookup[$sourceId])) {
                    continue;
                }

                $counts[$sourceId] = ($counts[$sourceId] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return [[], 0];
        }

        arsort($counts);
        $total = count($counts);
        $top = array_slice($counts, 0, self::COLLATERAL_SIZE, true);

        $elements = Craft::$app->getElements();
        $collateral = [];

        foreach ($top as $elementId => $count) {
            $element = $elements->getElementById($elementId, null, null, ['status' => null, 'trashed' => null]);

            if ($element === null) {
                continue;
            }

            $collateral[] = [
                'id' => (int)$element->id,
                'title' => (string)($element->title ?: $element::displayName() . ' #' . $element->id),
                'type' => $element::displayName(),
                'url' => $element->getCpEditUrl(),
                'count' => $count,
            ];
        }

        return [$collateral, $total];
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id: int, title: string, status: string|null, url: string|null}>
     */
    private function sample(ScopeInterface $scope, array $ids): array
    {
        $elementType = $scope->elementType();

        $elements = $elementType::find()
            ->id(array_slice($ids, 0, self::SAMPLE_SIZE))
            ->status(null)
            ->trashed(null)
            ->siteId('*')
            ->unique()
            ->limit(self::SAMPLE_SIZE)
            ->all();

        $sample = [];

        foreach ($elements as $element) {
            $sample[] = [
                'id' => (int)$element->id,
                'title' => (string)($element->title ?: $element::displayName() . ' #' . $element->id),
                'status' => $element->getStatus(),
                'url' => $element->getCpEditUrl(),
            ];
        }

        return $sample;
    }

    /**
     * File count and total bytes for an asset target, read from the `assets` table rather than
     * from the volumes — a remote volume would otherwise mean one HTTP call per file.
     *
     * @param int[] $ids
     * @return array{0: int, 1: int}
     */
    private function assetFootprint(array $ids): array
    {
        $files = 0;
        $bytes = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $row = (new Query())
                ->select(['n' => 'COUNT(*)', 'total' => 'SUM([[size]])'])
                ->from(Table::ASSETS)
                ->where(['id' => $chunk])
                ->one();

            $files += (int)($row['n'] ?? 0);
            $bytes += (int)($row['total'] ?? 0);
        }

        return [$files, $bytes];
    }

    /**
     * Warnings that come from the combination of options rather than from the scope itself.
     *
     * @return string[]
     */
    private function advisoryWarnings(Target $target, BlastRadius $radius): array
    {
        $warnings = [];

        if ($radius->promotedDescendants > 0) {
            $warnings[] = Craft::t('nuke', '{n, plural, =1{One descendant is} other{# descendants are}} not being deleted — Craft moves children up to where their parent was. They will end up at the top of the structure.', [
                'n' => $radius->promotedDescendants,
            ]);
        }

        if ($radius->collateralCount > 0) {
            $warnings[] = Craft::t('nuke', '{n, plural, =1{One element elsewhere on the site relates} other{# elements elsewhere on the site relate}} to what you are deleting. Those fields will empty out.', [
                'n' => $radius->collateralCount,
            ]);
        }

        if ($target->hardDelete) {
            $warnings[] = Craft::t('nuke', 'This is a permanent delete. Nothing goes to the trash and there is no undo short of the backup.');
        }

        if ($target->deleteRelations && !$target->hardDelete) {
            $warnings[] = Craft::t('nuke', 'Relations are being deleted too, so restoring these elements from the trash will not restore what pointed at them.');
        }

        if ($target->hardDelete && $radius->incomingRelations > 0) {
            $warnings[] = Craft::t('nuke', '{n} relations pointing at these elements will be cleared. Craft has no foreign key there, so leaving them would leave rows pointing at nothing.', [
                'n' => $radius->incomingRelations,
            ]);
        }

        if ($target->purgeHistory && !$target->hardDelete) {
            $warnings[] = Craft::t('nuke', 'Drafts and revisions are being removed permanently. Restoring these elements will bring them back with no history.');
        }

        if (!$target->backup) {
            $warnings[] = Craft::t('nuke', 'No backup is being taken before this runs.');
        }

        return $warnings;
    }

    /**
     * Whether the target names authors that the scope would silently leave out of its query.
     */
    private function authorsIgnoredBy(ScopeInterface $scope, Target $target): bool
    {
        return $target->ids('authorIds') !== []
            && $target->elementIds === []
            && !Plugin::getInstance()->scopes->acceptsAuthors($scope);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    private function log(string $message, string $level = 'info'): void
    {
        $level === 'error'
            ? Craft::error($message, Plugin::LOG_CATEGORY)
            : Craft::info($message, Plugin::LOG_CATEGORY);
    }
}
