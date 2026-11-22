<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

class AuditLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%audit_logs}}';
    }

    public function rules(): array
    {
        return [
            [['entity', 'entity_id', 'action'], 'required'],
            [['entity_id', 'actor_id'], 'integer'],
            [['entity'], 'string', 'max' => 40],
            [['action'], 'string', 'max' => 20],
            [['reason'], 'string'],
            [['old_values', 'new_values', 'created_at'], 'safe'],
        ];
    }

    public static function log(
        string $entity,
        int $entityId,
        string $action,
        array|null $oldValues = null,
        array|null $newValues = null,
        ?string $reason = null,
        ?int $actorId = null
    ): static {
        $log = new static();
        $log->entity = $entity;
        $log->entity_id = $entityId;
        $log->action = strtoupper($action);
        $log->old_values = $oldValues;
        $log->new_values = $newValues;
        $log->reason = $reason;
        $log->actor_id = $actorId ?? (Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null);
        $log->created_at = date('Y-m-d H:i:s');
        $log->save(false);

        return $log;
    }
}
