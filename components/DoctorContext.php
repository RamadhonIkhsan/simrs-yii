<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use app\models\Doctor;

class DoctorContext
{
    public static function getRoleCode(): ?string
    {
        $user = Yii::$app->user->identity;
        return $user?->role?->code;
    }

    public static function isDoctor(): bool
    {
        return self::getRoleCode() === 'DOCTOR';
    }

    public static function getCurrentDoctorId(): ?int
    {
        $userId = Yii::$app->user->id;
        if ($userId === null) {
            return null;
        }

        $docId = Doctor::find()
            ->select('id')
            ->where(['user_id' => (int)$userId])
            ->scalar();

        return $docId !== false && $docId !== null ? (int)$docId : null;
    }

    public static function resolveDoctorIdFilter(mixed $requestedDoctorId = null): ?int
    {
        if (self::isDoctor()) {
            return self::getCurrentDoctorId() ?? -1;
        }

        if ($requestedDoctorId !== null && $requestedDoctorId !== '' && is_numeric($requestedDoctorId)) {
            return (int)$requestedDoctorId;
        }

        return null;
    }

    public static function assertOwnsVisit(int $visitDoctorId): ?array
    {
        if (!self::isDoctor()) {
            return null;
        }

        $ownDoctorId = self::getCurrentDoctorId();
        if ($ownDoctorId === null || $ownDoctorId !== $visitDoctorId) {
            return [
                'rc'   => 403,
                'rm'   => 'Akses ditolak: Kunjungan ini bukan milik dokter yang sedang login',
                'data' => null,
            ];
        }

        return null;
    }
}
