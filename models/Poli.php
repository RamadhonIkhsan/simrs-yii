<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

class Poli extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%polis}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name'], 'required'],
            [['code'], 'string', 'min' => 2, 'max' => 10],
            [['code'], 'match', 'pattern' => '/^[A-Z0-9_-]{2,10}$/', 'message' => 'Kode poli hanya boleh berisi huruf besar, angka, tanda minus (-), dan garis bawah (_)'],
            [['name'], 'string', 'min' => 3, 'max' => 80],
            [['registration_fee'], 'number', 'min' => 0, 'max' => 99999999],
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
        if ($this->registration_fee === null || $this->registration_fee === '') {
            $this->registration_fee = 0;
        }

        return parent::beforeValidate();
    }
}
