<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class Procedure extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%procedures}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name', 'tariff'], 'required'],
            [['code'], 'string', 'min' => 1, 'max' => 20],
            [['code'], 'match', 'pattern' => '/^[A-Z0-9._-]{1,20}$/', 'message' => 'Kode tindakan hanya boleh berisi huruf besar, angka, titik (.), tanda minus (-), dan garis bawah (_)'],
            [['name'], 'string', 'min' => 3, 'max' => 150],
            [['tariff'], 'number', 'min' => 0, 'max' => 999999999],
            [['is_active'], 'boolean'],
            [['created_by', 'updated_by'], 'integer'],
            [['created_at', 'updated_at', 'deleted_at'], 'safe'],
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
        if ($this->is_active === null || $this->is_active === '') {
            $this->is_active = true;
        }

        return parent::beforeValidate();
    }
}
