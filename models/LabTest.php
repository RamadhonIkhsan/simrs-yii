<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class LabTest extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lab_tests}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name', 'tariff'], 'required'],
            [['code'], 'string', 'min' => 1, 'max' => 20],
            [['code'], 'match', 'pattern' => '/^[A-Z0-9._-]{1,20}$/', 'message' => 'Kode pemeriksaan lab hanya boleh berisi huruf besar, angka, titik (.), tanda minus (-), dan garis bawah (_)'],
            [['name'], 'string', 'min' => 1, 'max' => 150],
            [['unit'], 'string', 'max' => 20],
            [['ref_range'], 'string', 'max' => 60],
            [['tariff'], 'number', 'min' => 0, 'max' => 999999999],
            [['is_active'], 'boolean'],
            [['created_by', 'updated_by'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        if ($this->code !== null) {
            $this->code = strtoupper(trim((string)$this->code));
        }
        if ($this->name !== null) {
            $this->name = trim((string)$this->name);
        }
        if ($this->unit !== null) {
            $this->unit = trim((string)$this->unit);
            if ($this->unit === '') {
                $this->unit = null;
            }
        }
        if ($this->ref_range !== null) {
            $this->ref_range = trim((string)$this->ref_range);
            if ($this->ref_range === '') {
                $this->ref_range = null;
            }
        }
        if ($this->is_active === null || $this->is_active === '') {
            $this->is_active = true;
        }

        return parent::beforeValidate();
    }
}
