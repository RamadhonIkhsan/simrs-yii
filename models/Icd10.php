<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class Icd10 extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%icd10}}';
    }

    public static function primaryKey(): array
    {
        return ['code'];
    }

    public function rules(): array
    {
        return [
            [['code', 'name'], 'required'],
            [['code'], 'string', 'max' => 10],
            [['code'], 'match', 'pattern' => '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', 'message' => 'Format kode ICD-10 tidak valid (contoh: J00 atau E11.9)'],
            [['name'], 'string', 'min' => 1, 'max' => 255],
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

        return parent::beforeValidate();
    }
}
