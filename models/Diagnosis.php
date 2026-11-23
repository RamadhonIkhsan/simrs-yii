<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class Diagnosis extends ActiveRecord
{
    public const TYPE_PRIMARY   = 'PRIMARY';
    public const TYPE_SECONDARY = 'SECONDARY';

    public static function tableName(): string
    {
        return '{{%diagnoses}}';
    }

    public function rules(): array
    {
        return [
            [['examination_id', 'icd10_code', 'type'], 'required'],
            [['examination_id'], 'integer'],
            [['icd10_code'], 'string', 'max' => 10],
            [['type'], 'in', 'range' => [self::TYPE_PRIMARY, self::TYPE_SECONDARY]],
            [['notes'], 'string'],
            [['created_at'], 'safe'],
        ];
    }

    public function getIcd10()
    {
        return $this->hasOne(Icd10::class, ['code' => 'icd10_code']);
    }
}
