<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\models\AuditLog;
use app\models\Doctor;
use app\models\User;
use yii\db\IntegrityException;
use yii\db\Query;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class DoctorsController extends Controller
{
    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => [
                    'application/json' => Response::FORMAT_JSON,
                ],
            ],
            'authenticator' => [
                'class' => JwtAuth::class,
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index'   => ['GET'],
                    'options' => ['GET'],
                    'view'    => ['GET'],
                    'create'  => ['POST'],
                    'update'  => ['PUT', 'PATCH'],
                    'delete'  => ['DELETE'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $pageParam = $request->get('page');
        $sizeParam = $request->get('size');
        $search = trim((string)$request->get('search', ''));
        $staffType = trim((string)$request->get('staff_type', ''));
        $specialty = trim((string)$request->get('specialty', ''));
        $isActive = $request->get('is_active');

        $size = $sizeParam !== null ? (int)$sizeParam : 10;
        if ($size <= 0) {
            $size = 10;
        }

        if ($pageParam === null || (int)$pageParam <= 1) {
            $displayPage = 0;
            $offset = 0;
        } else {
            $displayPage = (int)$pageParam - 1;
            $offset = $displayPage * $size;
        }

        $query = Doctor::find()->with(['user']);

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'full_name', $search],
                ['ilike', 'str_no', $search],
                ['ilike', 'nip', $search],
            ]);
        }

        if ($staffType !== '') {
            $query->andWhere(['staff_type' => strtoupper($staffType)]);
        }

        if ($specialty !== '') {
            $query->andWhere(['ilike', 'specialty', $specialty]);
        }

        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        $totalElements = (int)(clone $query)->count();
        $totalPages = $size > 0 ? (int)ceil($totalElements / $size) : 0;
        if ($totalPages === 0 && $totalElements === 0) {
            $totalPages = 0;
        }

        $doctors = $query->orderBy(['id' => SORT_ASC])
            ->limit($size)
            ->offset($offset)
            ->all();

        $data = [];
        foreach ($doctors as $doctor) {
            $data[] = $this->dataDoctor($doctor);
        }

        return [
            'page' => $displayPage,
            'size' => $size,
            'totalPages' => $totalPages,
            'totalElements' => $totalElements,
            'data' => $data,
        ];
    }

    public function actionOptions(): array
    {
        $doctors = Doctor::find()
            ->select(['id', 'full_name', 'specialty'])
            ->where(['is_active' => true])
            ->orderBy(['full_name' => SORT_ASC])
            ->asArray()
            ->all();

        $data = [];
        foreach ($doctors as $d) {
            $data[] = [
                'id' => (int)$d['id'],
                'full_name' => $d['full_name'],
                'specialty' => $d['specialty'],
            ];
        }

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionView(int $id): array
    {
        $doctor = Doctor::find()
            ->with(['user'])
            ->where(['id' => $id])
            ->one();

        if (!$doctor) {
            return [
                'rc' => 404,
                'rm' => 'Data dokter tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('doctors', $id, 'VIEW');

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $this->dataDoctor($doctor),
        ];
    }

    public function actionCreate(): array
    {
        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        $strNo = trim((string)($params['str_no'] ?? ''));
        $nip = isset($params['nip']) ? trim((string)$params['nip']) : null;
        if ($nip === '') {
            $nip = null;
        }
        $userId = isset($params['user_id']) && $params['user_id'] !== '' ? (int)$params['user_id'] : null;

        if ($strNo !== '') {
            $existsStr = Doctor::find()
                ->where(['str_no' => $strNo])
                ->exists();
            if ($existsStr) {
                return [
                    'rc' => 409,
                    'rm' => "Nomor STR {$strNo} sudah digunakan",
                    'data' => null,
                ];
            }
        }

        if ($nip !== null) {
            $existsNip = Doctor::find()
                ->where(['nip' => $nip])
                ->exists();
            if ($existsNip) {
                return [
                    'rc' => 409,
                    'rm' => "NIP {$nip} sudah digunakan",
                    'data' => null,
                ];
            }
        }

        if ($userId !== null) {
            $userValidate = $this->validateUserForDoctor($userId);
            if ($userValidate !== null) {
                return $userValidate;
            }
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $doctor = new Doctor();
        $doctor->user_id = $userId;
        $doctor->nip = $nip;
        $doctor->str_no = $strNo;
        $doctor->full_name = trim((string)($params['full_name'] ?? ''));
        $doctor->specialty = isset($params['specialty']) ? trim((string)$params['specialty']) : null;
        $doctor->staff_type = isset($params['staff_type']) ? strtoupper(trim((string)$params['staff_type'])) : 'DOCTOR';
        $doctor->phone = isset($params['phone']) ? trim((string)$params['phone']) : null;
        $doctor->is_active = isset($params['is_active']) ? filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN) : true;
        $doctor->created_at = $now;
        $doctor->created_by = $currentUserId;
        $doctor->updated_at = $now;
        $doctor->updated_by = $currentUserId;

        try {
            if (!$doctor->save()) {
                return [
                    'rc' => 422,
                    'rm' => 'Validasi gagal',
                    'data' => $doctor->getErrors(),
                ];
            }
        } catch (IntegrityException $e) {
            return $this->handleIntegrityException($e, $strNo, $nip);
        }

        $data = $this->dataDoctor($doctor);
        AuditLog::log('doctors', (int)$doctor->id, 'CREATE', null, $data);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionUpdate(int $id): array
    {
        $doctor = Doctor::find()
            ->with(['user'])
            ->where(['id' => $id])
            ->one();

        if (!$doctor) {
            return [
                'rc' => 404,
                'rm' => 'Data dokter tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataDoctor($doctor);

        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        if (isset($params['str_no'])) {
            $newStr = trim((string)$params['str_no']);
            $existsStr = Doctor::find()
                ->where(['str_no' => $newStr])
                ->andWhere(['!=', 'id', $id])
                ->exists();
            if ($existsStr) {
                return [
                    'rc' => 409,
                    'rm' => "Nomor STR {$newStr} sudah digunakan",
                    'data' => null,
                ];
            }
            $doctor->str_no = $newStr;
        }

        if (array_key_exists('nip', $params)) {
            $newNip = trim((string)$params['nip']);
            if ($newNip !== '') {
                $existsNip = Doctor::find()
                    ->where(['nip' => $newNip])
                    ->andWhere(['!=', 'id', $id])
                    ->exists();
                if ($existsNip) {
                    return [
                        'rc' => 409,
                        'rm' => "NIP {$newNip} sudah digunakan",
                        'data' => null,
                    ];
                }
                $doctor->nip = $newNip;
            } else {
                $doctor->nip = null;
            }
        }

        if (array_key_exists('user_id', $params)) {
            $newUserId = $params['user_id'] !== '' && $params['user_id'] !== null ? (int)$params['user_id'] : null;
            if ($newUserId !== null) {
                $userValidate = $this->validateUserForDoctor($newUserId, $id);
                if ($userValidate !== null) {
                    return $userValidate;
                }
            }
            $doctor->user_id = $newUserId;
        }

        if (isset($params['full_name'])) {
            $doctor->full_name = trim((string)$params['full_name']);
        }
        if (array_key_exists('specialty', $params)) {
            $spec = trim((string)$params['specialty']);
            $doctor->specialty = $spec !== '' ? $spec : null;
        }
        if (isset($params['staff_type'])) {
            $doctor->staff_type = strtoupper(trim((string)$params['staff_type']));
        }
        if (array_key_exists('phone', $params)) {
            $ph = trim((string)$params['phone']);
            $doctor->phone = $ph !== '' ? $ph : null;
        }
        if (isset($params['is_active'])) {
            $doctor->is_active = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $doctor->updated_at = date('Y-m-d H:i:s');
        $doctor->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        try {
            if (!$doctor->save()) {
                return [
                    'rc' => 422,
                    'rm' => 'Validasi gagal',
                    'data' => $doctor->getErrors(),
                ];
            }
        } catch (IntegrityException $e) {
            return $this->handleIntegrityException($e, $doctor->str_no, $doctor->nip);
        }

        $doctor->refresh();
        $newValues = $this->dataDoctor($doctor);
        AuditLog::log('doctors', (int)$doctor->id, 'UPDATE', $oldValues, $newValues);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $newValues,
        ];
    }

    public function actionDelete(int $id): array
    {
        $doctor = Doctor::find()
            ->with(['user'])
            ->where(['id' => $id])
            ->one();

        if (!$doctor) {
            return [
                'rc' => 404,
                'rm' => 'Data dokter tidak ditemukan',
                'data' => null,
            ];
        }

        $hasActiveSchedule = (new Query())
            ->from('doctor_schedules')
            ->where(['doctor_id' => $id, 'is_active' => true])
            ->exists();

        if ($hasActiveSchedule) {
            return [
                'rc' => 409,
                'rm' => 'Dokter masih memiliki jadwal praktik aktif',
                'data' => null,
            ];
        }

        $hasActiveVisits = (new Query())
            ->from('visits')
            ->where(['doctor_id' => $id])
            ->andWhere(['in', 'status', ['WAITING', 'CALLED', 'EXAMINING']])
            ->exists();

        if ($hasActiveVisits) {
            return [
                'rc' => 409,
                'rm' => 'Dokter masih memiliki antrean atau kunjungan pasien yang sedang berjalan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataDoctor($doctor);

        try {
            $doctor->delete();
        } catch (\yii\db\IntegrityException $e) {
            return [
                'rc'   => 409,
                'rm'   => 'Dokter tidak dapat dihapus karena masih terkait dengan data lain',
                'data' => null,
            ];
        }

        AuditLog::log('doctors', (int)$doctor->id, 'DELETE', $oldValues, null);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => null,
        ];
    }

    private function validateUserForDoctor(int $userId, ?int $currentDoctorId = null): ?array
    {
        $userAccount = User::findOne(['id' => $userId, 'is_active' => true]);
        if (!$userAccount) {
            return [
                'rc' => 422,
                'rm' => 'User akun tidak ditemukan atau tidak aktif',
                'data' => null,
            ];
        }

        $hasDoctorRole = (new Query())
            ->from('roles')
            ->where(['id' => $userAccount->roles, 'code' => 'DOCTOR'])
            ->exists();

        if (!$hasDoctorRole) {
            return [
                'rc' => 422,
                'rm' => 'User akun harus memiliki role DOCTOR',
                'data' => null,
            ];
        }

        $queryLinked = Doctor::find()
            ->where(['user_id' => $userId]);

        if ($currentDoctorId !== null) {
            $queryLinked->andWhere(['!=', 'id', $currentDoctorId]);
        }

        if ($queryLinked->exists()) {
            return [
                'rc' => 409,
                'rm' => 'User akun sudah terkait dengan dokter lain',
                'data' => null,
            ];
        }

        return null;
    }

    private function handleIntegrityException(IntegrityException $e, ?string $strNo, ?string $nip): array
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'ux_doctors_str')) {
            return [
                'rc' => 409,
                'rm' => "Nomor STR {$strNo} sudah digunakan",
                'data' => null,
            ];
        }
        if (str_contains($msg, 'ux_doctors_nip')) {
            return [
                'rc' => 409,
                'rm' => "NIP {$nip} sudah digunakan",
                'data' => null,
            ];
        }
        if (str_contains($msg, 'doctors_user_id_key')) {
            return [
                'rc' => 409,
                'rm' => 'User akun sudah terkait dengan dokter lain',
                'data' => null,
            ];
        }

        throw $e;
    }

    private function dataDoctor(Doctor $doctor): array
    {
        $userData = null;
        if ($doctor->user_id !== null) {
            $u = $doctor->user;
            if ($u) {
                $userData = [
                    'id' => (int)$u->id,
                    'username' => $u->username,
                    'full_name' => $u->full_name,
                ];
            }
        }

        return [
            'id' => (int)$doctor->id,
            'user_id' => $doctor->user_id !== null ? (int)$doctor->user_id : null,
            'user' => $userData,
            'nip' => $doctor->nip,
            'str_no' => $doctor->str_no,
            'full_name' => $doctor->full_name,
            'specialty' => $doctor->specialty,
            'staff_type' => $doctor->staff_type,
            'phone' => $doctor->phone,
            'is_active' => (bool)$doctor->is_active,
            'created_at' => $doctor->created_at,
            'created_by' => $doctor->created_by !== null ? (int)$doctor->created_by : null,
            'updated_at' => $doctor->updated_at,
            'updated_by' => $doctor->updated_by !== null ? (int)$doctor->updated_by : null,
        ];
    }
}
