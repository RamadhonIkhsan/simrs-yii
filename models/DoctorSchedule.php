<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class DoctorSchedule extends ActiveRecord
{
    public const DAY_NAMES = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];

    public static function tableName(): string
    {
        return '{{%doctor_schedules}}';
    }

    public function rules(): array
    {
        return [
            [['doctor_id', 'poli_id', 'day_of_week', 'start_time', 'end_time'], 'required'],
            [['doctor_id', 'poli_id', 'day_of_week', 'quota', 'created_by', 'updated_by'], 'integer'],
            [['day_of_week'], 'integer', 'min' => 1, 'max' => 7],
            [['quota'], 'integer', 'min' => 1, 'max' => 500],
            [['start_time', 'end_time'], 'match', 'pattern' => '/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', 'message' => 'Format jam harus HH:MM atau HH:MM:SS'],
            [['end_time'], 'validateTimeRange'],
            [['is_active'], 'boolean'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        if ($this->start_time !== null) {
            $this->start_time = trim((string)$this->start_time);
        }
        if ($this->end_time !== null) {
            $this->end_time = trim((string)$this->end_time);
        }
        if ($this->quota === null || $this->quota === '') {
            $this->quota = 30;
        }
        if ($this->is_active === null || $this->is_active === '') {
            $this->is_active = true;
        }

        return parent::beforeValidate();
    }

    public function validateTimeRange(string $attribute): void
    {
        if ($this->hasErrors('start_time') || $this->hasErrors('end_time')) {
            return;
        }

        $startSec = strtotime('1970-01-01 ' . $this->start_time);
        $endSec = strtotime('1970-01-01 ' . $this->end_time);

        if ($startSec === false || $endSec === false || $endSec <= $startSec) {
            $this->addError($attribute, 'Jam selesai harus lebih besar dari jam mulai');
        }
    }

    public static function getDayName(int $dayOfWeek): string
    {
        return self::DAY_NAMES[$dayOfWeek] ?? '';
    }

    public function getDoctor()
    {
        return $this->hasOne(Doctor::class, ['id' => 'doctor_id']);
    }

    public function getPoli()
    {
        return $this->hasOne(Poli::class, ['id' => 'poli_id']);
    }
}
