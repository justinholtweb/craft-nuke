<?php

namespace justinholtweb\nuke\records;

use craft\db\ActiveRecord;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * One entry in the run ledger.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string|null $summary
 * @property string|null $note
 * @property array|null $target
 * @property array|null $preview
 * @property array|null $outcome
 * @property string|null $backupPath
 * @property int $removed
 * @property int $failed
 * @property float $duration
 * @property bool $dryRun
 * @property bool $scheduled
 * @property int|null $userId
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class RunRecord extends ActiveRecord
{
    public const TYPE_STRIKE = 'strike';
    public const TYPE_SWEEP = 'sweep';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return '{{%nuke_runs}}';
    }

    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
