<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class Visit extends ActiveRecord
{
    public const STATUS_WAITING   = 'WAITING';
    public const STATUS_CALLED    = 'CALLED';
    public const STATUS_EXAMINING = 'EXAMINING';
    public const STATUS_DONE      = 'DONE';
    public const STATUS_CANCELLED = 'CANCELLED';

    public static function tableName(): string
    {
        return '{{%visits}}';
    }

    public function rules(): array
    {
        return [
            [['visit_no', 'patient_id', 'poli_id', 'doctor_id', 'visit_date', 'queue_no', 'status'], 'required'],
            [['patient_id', 'poli_id', 'doctor_id', 'schedule_id', 'queue_no', 'created_by', 'updated_by'], 'integer'],
            [['visit_no'], 'string', 'max' => 20],
            [['status'], 'in', 'range' => [
                self::STATUS_WAITING,
                self::STATUS_CALLED,
                self::STATUS_EXAMINING,
                self::STATUS_DONE,
                self::STATUS_CANCELLED,
            ]],
            [['registration_fee'], 'number', 'min' => 0],
            [['visit_date', 'created_at', 'updated_at'], 'safe'],
        ];
    }

    public function getPatient()
    {
        return $this->hasOne(Patient::class, ['id' => 'patient_id']);
    }

    public function getPoli()
    {
        return $this->hasOne(Poli::class, ['id' => 'poli_id']);
    }

    public function getDoctor()
    {
        return $this->hasOne(Doctor::class, ['id' => 'doctor_id']);
    }

    public function getSchedule()
    {
        return $this->hasOne(DoctorSchedule::class, ['id' => 'schedule_id']);
    }
}
