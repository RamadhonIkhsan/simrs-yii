<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class Bill extends ActiveRecord
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_ISSUED = 'ISSUED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const PAYMENT_STATUS_UNPAID = 'UNPAID';
    public const PAYMENT_STATUS_PARTIAL = 'PARTIAL';
    public const PAYMENT_STATUS_PAID = 'PAID';

    public static function tableName(): string
    {
        return '{{%bills}}';
    }

    public function rules(): array
    {
        return [
            [['bill_no', 'visit_id', 'patient_id'], 'required'],
            [['visit_id', 'patient_id', 'version', 'created_by', 'updated_by'], 'integer'],
            [['total_amount', 'paid_amount'], 'number', 'min' => 0],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_CANCELLED]],
            [['payment_status'], 'in', 'range' => [self::PAYMENT_STATUS_UNPAID, self::PAYMENT_STATUS_PARTIAL, self::PAYMENT_STATUS_PAID]],
            [['bill_no'], 'string', 'max' => 20],
            [['issued_at', 'created_at', 'updated_at'], 'safe'],
            [['visit_id'], 'unique'],
            [['bill_no'], 'unique'],
        ];
    }

    public function getVisit()
    {
        return $this->hasOne(Visit::class, ['id' => 'visit_id']);
    }

    public function getPatient()
    {
        return $this->hasOne(Patient::class, ['id' => 'patient_id']);
    }

    public function getItems()
    {
        return $this->hasMany(BillItem::class, ['bill_id' => 'id'])->orderBy(['id' => SORT_ASC]);
    }

    public function getPayments()
    {
        return $this->hasMany(Payment::class, ['bill_id' => 'id'])->orderBy(['paid_at' => SORT_ASC, 'id' => SORT_ASC]);
    }
}
