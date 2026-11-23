<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class Drug extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%drugs}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name', 'unit', 'price'], 'required'],
            [['code'], 'string', 'min' => 1, 'max' => 20],
            [['code'], 'match', 'pattern' => '/^[A-Z0-9._-]{1,20}$/', 'message' => 'Kode obat hanya boleh berisi huruf besar, angka, titik (.), tanda minus (-), dan garis bawah (_)'],
            [['name'], 'string', 'min' => 3, 'max' => 150],
            [['unit'], 'string', 'min' => 1, 'max' => 20],
            [['price'], 'number', 'min' => 0, 'max' => 999999999],
            [['stock', 'min_stock'], 'integer', 'min' => 0],
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
        if ($this->unit !== null) {
            $this->unit = trim((string)$this->unit);
        }
        if ($this->stock === null || $this->stock === '') {
            $this->stock = 0;
        }
        if ($this->min_stock === null || $this->min_stock === '') {
            $this->min_stock = 0;
        }
        if ($this->is_active === null || $this->is_active === '') {
            $this->is_active = true;
        }

        return parent::beforeValidate();
    }
}
