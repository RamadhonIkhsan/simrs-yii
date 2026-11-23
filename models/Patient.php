<?php

declare(strict_types=1);

namespace app\models;

use DateTime;
use yii\db\ActiveRecord;

class Patient extends ActiveRecord
{
    public const VALID_BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public static function tableName(): string
    {
        return '{{%patients}}';
    }

    public function rules(): array
    {
        return [
            [['full_name', 'gender', 'birth_date'], 'required'],
            [['full_name'], 'string', 'min' => 3, 'max' => 120],
            [['nik'], 'match', 'pattern' => '/^[0-9]{16}$/', 'message' => 'NIK harus terdiri dari tepat 16 digit angka'],
            [['gender'], 'in', 'range' => ['L', 'P'], 'message' => 'Jenis kelamin harus L atau P'],
            [['birth_date'], 'validateBirthDate'],
            [['birth_place'], 'string', 'max' => 80],
            [['address'], 'string'],
            [['phone'], 'string', 'max' => 20],
            [['phone'], 'match', 'pattern' => '/^\+?[0-9]{6,20}$/', 'message' => 'Nomor telepon hanya boleh berisi angka dan tanda + di awal'],
            [['blood_type'], 'in', 'range' => self::VALID_BLOOD_TYPES, 'message' => 'Golongan darah harus salah satu dari: A+, A-, B+, B-, AB+, AB-, O+, O-'],
            [['created_by', 'updated_by'], 'integer'],
            [['created_at', 'updated_at', 'deleted_at'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        if ($this->full_name !== null) {
            $this->full_name = self::normalizeName((string)$this->full_name);
        }

        $this->nik = self::normalizeNullableString($this->nik);
        $this->gender = self::normalizeNullableString($this->gender);
        if ($this->gender !== null) {
            $this->gender = strtoupper($this->gender);
        }

        $this->birth_date = self::normalizeNullableString($this->birth_date);
        $this->birth_place = self::normalizeNullableString($this->birth_place);
        $this->address = self::normalizeNullableString($this->address);
        $this->phone = self::normalizePhone($this->phone);

        $this->blood_type = self::normalizeNullableString($this->blood_type);
        if ($this->blood_type !== null) {
            $this->blood_type = strtoupper($this->blood_type);
        }

        return parent::beforeValidate();
    }

    public function validateBirthDate(string $attribute): void
    {
        $val = (string)$this->$attribute;
        $dt = DateTime::createFromFormat('Y-m-d', $val);
        if (!$dt || $dt->format('Y-m-d') !== $val) {
            $this->addError($attribute, 'Format tanggal lahir harus YYYY-MM-DD yang valid');
            return;
        }

        $today = new DateTime('today');
        $minDate = (new DateTime('today'))->modify('-130 years');

        if ($dt > $today) {
            $this->addError($attribute, 'Tanggal lahir tidak boleh di masa depan');
            return;
        }

        if ($dt < $minDate) {
            $this->addError($attribute, 'Tanggal lahir tidak boleh lebih dari 130 tahun yang lalu');
        }
    }

    public static function normalizeName(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return (string)preg_replace('/\s+/u', ' ', trim($value));
    }

    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $cleaned = (string)preg_replace('/[\s\-]+/', '', trim($value));
        return $cleaned === '' ? null : $cleaned;
    }

    public static function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string)$value);
        return $trimmed === '' ? null : $trimmed;
    }

    public function getAge(): ?int
    {
        if (empty($this->birth_date)) {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d', (string)$this->birth_date);
        if (!$dt) {
            return null;
        }
        return $dt->diff(new DateTime('today'))->y;
    }
}
