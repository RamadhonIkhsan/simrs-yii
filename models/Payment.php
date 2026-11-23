<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class Payment extends ActiveRecord
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_VOIDED = 'VOIDED';

    public const METHOD_CASH = 'CASH';
    public const METHOD_NON_CASH = 'NON_CASH';

    public static function tableName(): string
    {
        return '{{%payments}}';
    }

    public function rules(): array
    {
        return [
            [['payment_no', 'bill_id', 'amount', 'method', 'idempotency_key', 'cashier_id'], 'required'],
            [['bill_id', 'cashier_id'], 'integer'],
            [['amount'], 'number', 'min' => 0.01],
            [['method'], 'in', 'range' => [self::METHOD_CASH, self::METHOD_NON_CASH]],
            [['status'], 'in', 'range' => [self::STATUS_SUCCESS, self::STATUS_VOIDED]],
            [['payment_no'], 'string', 'max' => 30],
            [['channel'], 'string', 'max' => 50],
            [['reference_no'], 'string', 'max' => 60],
            [['idempotency_key'], 'string', 'min' => 8, 'max' => 80],
            [['paid_at'], 'safe'],
            [['payment_no'], 'unique'],
            [['idempotency_key'], 'unique'],
        ];
    }

    public function getBill()
    {
        return $this->hasOne(Bill::class, ['id' => 'bill_id']);
    }

    public function getCashier()
    {
        return $this->hasOne(User::class, ['id' => 'cashier_id']);
    }
}
