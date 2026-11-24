<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

class Doctor extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%doctors}}';
    }

    public function rules(): array
    {
        return [
            [['str_no', 'full_name'], 'required'],
            [['full_name'], 'string', 'min' => 3, 'max' => 120],
            [['str_no', 'nip'], 'string', 'max' => 30],
            [['specialty'], 'string', 'max' => 80],
            [['staff_type'], 'string', 'max' => 20],
            [['staff_type'], 'in', 'range' => ['DOCTOR', 'NURSE', 'MIDWIFE', 'OTHER']],
            [['phone'], 'string', 'max' => 20],
            [['phone'], 'match', 'pattern' => '/^\+?[0-9]{8,20}$/', 'message' => 'Format nomor telepon tidak valid'],
            [['user_id', 'created_by', 'updated_by'], 'integer'],
            [['is_active'], 'boolean'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        if ($this->full_name !== null) {
            $this->full_name = trim((string)$this->full_name);
        }
        if ($this->str_no !== null) {
            $this->str_no = trim((string)$this->str_no);
        }
        if ($this->nip !== null) {
            $this->nip = trim((string)$this->nip);
            if ($this->nip === '') {
                $this->nip = null;
            }
        }
        if ($this->phone !== null) {
            $this->phone = trim((string)$this->phone);
            if ($this->phone === '') {
                $this->phone = null;
            }
        }
        if ($this->specialty !== null) {
            $this->specialty = trim((string)$this->specialty);
            if ($this->specialty === '') {
                $this->specialty = null;
            }
        }
        if (empty($this->staff_type)) {
            $this->staff_type = 'DOCTOR';
        }

        return parent::beforeValidate();
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
