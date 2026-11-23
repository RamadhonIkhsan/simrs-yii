<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class StockMovement extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stock_movements}}';
    }

    public function rules(): array
    {
        return [
            [['drug_id', 'movement_type', 'qty_change', 'stock_after'], 'required'],
            [['drug_id', 'qty_change', 'stock_after', 'ref_id', 'created_by'], 'integer'],
            [['movement_type'], 'in', 'range' => ['IN', 'OUT_DISPENSE', 'ADJUSTMENT', 'RETURN']],
            [['qty_change'], 'compare', 'compareValue' => 0, 'operator' => '!=', 'type' => 'number'],
            [['stock_after'], 'integer', 'min' => 0],
            [['ref_type'], 'string', 'max' => 20],
            [['notes'], 'string'],
            [['created_at'], 'safe'],
        ];
    }
}
