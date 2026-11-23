<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class Examination extends ActiveRecord
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_FINAL = 'FINAL';

    public static function tableName(): string
    {
        return '{{%examinations}}';
    }

    public function rules(): array
    {
        return [
            [['visit_id', 'doctor_id'], 'required'],
            [['visit_id', 'doctor_id', 'version', 'created_by', 'updated_by'], 'integer'],
            [['complaint', 'anamnesis', 'physical_exam', 'plan_notes'], 'string'],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_FINAL]],
            [['finalized_at', 'created_at', 'updated_at'], 'safe'],
        ];
    }

    public function getVisit()
    {
        return $this->hasOne(Visit::class, ['id' => 'visit_id']);
    }

    public function getDoctor()
    {
        return $this->hasOne(Doctor::class, ['id' => 'doctor_id']);
    }

    public function getVitalSign()
    {
        return $this->hasOne(VitalSign::class, ['examination_id' => 'id']);
    }

    public function getDiagnoses()
    {
        return $this->hasMany(Diagnosis::class, ['examination_id' => 'id'])
            ->orderBy(['type' => SORT_ASC, 'id' => SORT_ASC]);
    }

    public function getRevisions()
    {
        return $this->hasMany(ExaminationRevision::class, ['examination_id' => 'id'])
            ->orderBy(['version' => SORT_DESC]);
    }
}
