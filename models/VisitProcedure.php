<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class VisitProcedure extends ActiveRecord
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_VOIDED = 'VOIDED';

    public static function tableName(): string
    {
        return '{{%visit_procedures}}';
    }

    public function rules(): array
    {
        return [
            [['visit_id', 'procedure_id', 'doctor_id', 'qty', 'unit_tariff'], 'required'],
            [['visit_id', 'procedure_id', 'doctor_id', 'qty', 'created_by'], 'integer'],
            [['qty'], 'integer', 'min' => 1, 'max' => 99],
            [['unit_tariff'], 'number', 'min' => 0],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_VOIDED]],
            [['void_reason'], 'string'],
            [['performed_at', 'created_at'], 'safe'],
        ];
    }

    public function getProcedure()
    {
        return $this->hasOne(Procedure::class, ['id' => 'procedure_id']);
    }

    public function getVisit()
    {
        return $this->hasOne(Visit::class, ['id' => 'visit_id']);
    }

    public function getDoctor()
    {
        return $this->hasOne(Doctor::class, ['id' => 'doctor_id']);
    }
}
