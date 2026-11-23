<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class VitalSign extends ActiveRecord
{
    public const MEASUREMENT_FIELDS = [
        'systolic',
        'diastolic',
        'pulse',
        'resp_rate',
        'temperature',
        'spo2',
        'weight_kg',
        'height_cm',
    ];

    public static function tableName(): string
    {
        return '{{%vital_signs}}';
    }

    public function rules(): array
    {
        return [
            [['examination_id'], 'required'],
            [['examination_id'], 'integer'],
            [['systolic'], 'integer', 'min' => 30, 'max' => 300],
            [['diastolic'], 'integer', 'min' => 20, 'max' => 200],
            [['diastolic'], 'validateDiastolic'],
            [['pulse'], 'integer', 'min' => 20, 'max' => 300],
            [['resp_rate'], 'integer', 'min' => 5, 'max' => 80],
            [['temperature'], 'number', 'min' => 25, 'max' => 45],
            [['spo2'], 'integer', 'min' => 0, 'max' => 100],
            [['weight_kg'], 'number', 'max' => 500],
            [['weight_kg'], 'compare', 'compareValue' => 0, 'operator' => '>', 'type' => 'number', 'message' => 'Berat badan harus lebih besar dari 0'],
            [['height_cm'], 'number', 'max' => 250],
            [['height_cm'], 'compare', 'compareValue' => 0, 'operator' => '>', 'type' => 'number', 'message' => 'Tinggi badan harus lebih besar dari 0'],
            [['measured_at'], 'safe'],
        ];
    }

    public function validateDiastolic(string $attribute): void
    {
        if ($this->systolic !== null && $this->systolic !== '' && $this->diastolic !== null && $this->diastolic !== '') {
            if ((int)$this->diastolic >= (int)$this->systolic) {
                $this->addError($attribute, 'Tekanan darah diastolik harus lebih kecil dari sistolik');
            }
        }
    }

    public function hasAnyMeasurement(): bool
    {
        foreach (self::MEASUREMENT_FIELDS as $field) {
            $val = $this->getAttribute($field);
            if ($val !== null && $val !== '') {
                return true;
            }
        }
        return false;
    }

    public function toArraySummary(): array
    {
        return [
            'id'             => $this->id !== null ? (int)$this->id : null,
            'examination_id' => (int)$this->examination_id,
            'systolic'       => $this->systolic !== null ? (int)$this->systolic : null,
            'diastolic'      => $this->diastolic !== null ? (int)$this->diastolic : null,
            'pulse'          => $this->pulse !== null ? (int)$this->pulse : null,
            'resp_rate'      => $this->resp_rate !== null ? (int)$this->resp_rate : null,
            'temperature'    => $this->temperature !== null ? (float)$this->temperature : null,
            'spo2'           => $this->spo2 !== null ? (int)$this->spo2 : null,
            'weight_kg'      => $this->weight_kg !== null ? (float)$this->weight_kg : null,
            'height_cm'      => $this->height_cm !== null ? (float)$this->height_cm : null,
            'measured_at'    => $this->measured_at,
        ];
    }
}
