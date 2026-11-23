<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class BillItem extends ActiveRecord
{
    public const TYPE_REGISTRATION = 'REGISTRATION';
    public const TYPE_PROCEDURE = 'PROCEDURE';
    public const TYPE_LAB = 'LAB';
    public const TYPE_DRUG = 'DRUG';
    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public static function tableName(): string
    {
        return '{{%bill_items}}';
    }

    public function rules(): array
    {
        return [
            [['bill_id', 'item_type', 'description', 'qty', 'unit_price'], 'required'],
            [['bill_id', 'ref_id', 'qty'], 'integer'],
            [['qty'], 'integer', 'min' => 1],
            [['unit_price', 'subtotal'], 'number'],
            [['item_type'], 'in', 'range' => [
                self::TYPE_REGISTRATION,
                self::TYPE_PROCEDURE,
                self::TYPE_LAB,
                self::TYPE_DRUG,
                self::TYPE_ADJUSTMENT,
            ]],
            [['ref_table'], 'string', 'max' => 50],
            [['description'], 'string', 'max' => 255],
            [['created_at'], 'safe'],
        ];
    }

    public function getBill()
    {
        return $this->hasOne(Bill::class, ['id' => 'bill_id']);
    }
}
