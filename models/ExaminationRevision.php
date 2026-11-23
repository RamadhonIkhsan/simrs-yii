<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

class ExaminationRevision extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%examination_revisions}}';
    }

    public function rules(): array
    {
        return [
            [['examination_id', 'version', 'snapshot'], 'required'],
            [['examination_id', 'version', 'changed_by'], 'integer'],
            [['reason'], 'string'],
            [['snapshot', 'changed_at'], 'safe'],
        ];
    }

    public function getChanger()
    {
        return $this->hasOne(User::class, ['id' => 'changed_by']);
    }
}
