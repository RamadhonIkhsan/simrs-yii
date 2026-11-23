<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\models\AuditLog;
use app\models\Patient;
use DateTime;
use yii\db\Exception as DbException;
use yii\db\Query;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class PatientsController extends Controller
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
                    'index'           => ['GET'],
                    'view'            => ['GET'],
                    'create'          => ['POST'],
                    'update'          => ['PUT', 'PATCH'],
                    'delete'          => ['DELETE'],
                    'check-duplicate' => ['POST'],
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
        $gender = trim((string)$request->get('gender', ''));
        $bloodType = trim((string)$request->get('blood_type', ''));
        $sortParam = $request->get('sort');
        $orderParam = strtolower((string)$request->get('order', 'asc'));

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

        $query = Patient::find()->where(['deleted_at' => null]);

        if ($search !== '') {
            $normalizedSearch = Patient::normalizeName($search);
            $query->andWhere([
                'or',
                ['ilike', 'medical_record_no', $normalizedSearch],
                ['ilike', 'nik', $normalizedSearch],
                ['ilike', 'full_name', $normalizedSearch],
            ]);
        }

        if ($gender !== '') {
            $query->andWhere(['gender' => strtoupper($gender)]);
        }

        if ($bloodType !== '') {
            $query->andWhere(['blood_type' => strtoupper($bloodType)]);
        }

        $allowedSort = [
            'full_name'         => 'full_name',
            'medical_record_no' => 'medical_record_no',
            'birth_date'        => 'birth_date',
            'created_at'        => 'created_at',
        ];

        $orderBy = ['id' => SORT_ASC];
        if ($sortParam !== null && is_string($sortParam)) {
            $isDesc = str_starts_with($sortParam, '-') || $orderParam === 'desc';
            $cleanSort = ltrim($sortParam, '-');
            if (isset($allowedSort[$cleanSort])) {
                $orderBy = [
                    $allowedSort[$cleanSort] => $isDesc ? SORT_DESC : SORT_ASC,
                    'id' => SORT_ASC,
                ];
            }
        }

        $totalElements = (int)(clone $query)->count();
        $totalPages = $size > 0 ? (int)ceil($totalElements / $size) : 0;

        $patients = $query->orderBy($orderBy)
            ->limit($size)
            ->offset($offset)
            ->all();

        $data = [];
        foreach ($patients as $patient) {
            $data[] = $this->dataPatient($patient);
        }

        return [
            'page' => $displayPage,
            'size' => $size,
            'totalPages' => $totalPages,
            'totalElements' => $totalElements,
            'data' => $data,
        ];
    }

    public function actionView(int $id): array
    {
        $patient = Patient::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$patient) {
            return [
                'rc' => 404,
                'rm' => 'Data pasien tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('patients', $id, 'VIEW');

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $this->dataPatient($patient),
        ];
    }

    public function actionCreate(): array
    {
        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        if (array_key_exists('medical_record_no', $params) && $params['medical_record_no'] !== null && trim((string)$params['medical_record_no']) !== '') {
            return [
                'rc' => 422,
                'rm' => 'Nomor rekam medis dibuat otomatis oleh sistem dan tidak boleh diisi dari request',
                'data' => ['medical_record_no' => ['Nomor rekam medis tidak boleh diisi manual']],
            ];
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $patient = new Patient();
        $patient->nik = $params['nik'] ?? null;
        $patient->full_name = $params['full_name'] ?? null;
        $patient->gender = $params['gender'] ?? null;
        $patient->birth_date = $params['birth_date'] ?? null;
        $patient->birth_place = $params['birth_place'] ?? null;
        $patient->address = $params['address'] ?? null;
        $patient->phone = $params['phone'] ?? null;
        $patient->blood_type = $params['blood_type'] ?? null;
        $patient->created_at = $now;
        $patient->created_by = $currentUserId;
        $patient->updated_at = $now;
        $patient->updated_by = $currentUserId;

        if (!$patient->validate()) {
            return [
                'rc' => 422,
                'rm' => 'Validasi gagal',
                'data' => $patient->getErrors(),
            ];
        }

        $conflict = $this->checkDuplicateConflict($patient);
        if ($conflict !== null) {
            return $conflict;
        }

        try {
            $patient->save(false);
        } catch (DbException $e) {
            return $this->handleDbException($e, $patient);
        }

        $patient->refresh();
        $data = $this->dataPatient($patient);
        AuditLog::log('patients', (int)$patient->id, 'CREATE', null, $data);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionUpdate(int $id): array
    {
        $patient = Patient::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$patient) {
            return [
                'rc' => 404,
                'rm' => 'Data pasien tidak ditemukan',
                'data' => null,
            ];
        }

        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        if (array_key_exists('medical_record_no', $params) && $params['medical_record_no'] !== null) {
            $sentMr = trim((string)$params['medical_record_no']);
            if ($sentMr !== '' && $sentMr !== (string)$patient->medical_record_no) {
                return [
                    'rc' => 422,
                    'rm' => 'Nomor rekam medis tidak boleh diubah',
                    'data' => ['medical_record_no' => ['Nomor rekam medis tidak boleh diubah']],
                ];
            }
        }

        $oldValues = $this->dataPatient($patient);

        if (array_key_exists('nik', $params)) {
            $patient->nik = $params['nik'];
        }
        if (array_key_exists('full_name', $params)) {
            $patient->full_name = $params['full_name'];
        }
        if (array_key_exists('gender', $params)) {
            $patient->gender = $params['gender'];
        }
        if (array_key_exists('birth_date', $params)) {
            $patient->birth_date = $params['birth_date'];
        }
        if (array_key_exists('birth_place', $params)) {
            $patient->birth_place = $params['birth_place'];
        }
        if (array_key_exists('address', $params)) {
            $patient->address = $params['address'];
        }
        if (array_key_exists('phone', $params)) {
            $patient->phone = $params['phone'];
        }
        if (array_key_exists('blood_type', $params)) {
            $patient->blood_type = $params['blood_type'];
        }

        $patient->updated_at = date('Y-m-d H:i:s');
        $patient->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        if (!$patient->validate()) {
            return [
                'rc' => 422,
                'rm' => 'Validasi gagal',
                'data' => $patient->getErrors(),
            ];
        }

        $conflict = $this->checkDuplicateConflict($patient, $id);
        if ($conflict !== null) {
            return $conflict;
        }

        try {
            $patient->save(false);
        } catch (DbException $e) {
            return $this->handleDbException($e, $patient, $id);
        }

        $patient->refresh();
        $newValues = $this->dataPatient($patient);
        AuditLog::log('patients', (int)$patient->id, 'UPDATE', $oldValues, $newValues);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $newValues,
        ];
    }

    public function actionDelete(int $id): array
    {
        $patient = Patient::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$patient) {
            return [
                'rc' => 404,
                'rm' => 'Data pasien tidak ditemukan',
                'data' => null,
            ];
        }

        $hasActiveVisits = (new Query())
            ->from('visits')
            ->where(['patient_id' => $id])
            ->andWhere(['in', 'status', ['WAITING', 'CALLED', 'EXAMINING']])
            ->exists();

        if ($hasActiveVisits) {
            return [
                'rc' => 409,
                'rm' => 'Pasien masih memiliki kunjungan aktif (WAITING/CALLED/EXAMINING)',
                'data' => null,
            ];
        }

        $oldValues = $this->dataPatient($patient);

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $patient->deleted_at = $now;
        $patient->updated_at = $now;
        $patient->updated_by = $currentUserId;
        $patient->save(false);

        AuditLog::log('patients', $id, 'DELETE', $oldValues, null);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => null,
        ];
    }

    public function actionCheckDuplicate(): array
    {
        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        $nik = Patient::normalizeNullableString($params['nik'] ?? null);
        $fullName = Patient::normalizeName( isset($params['full_name']) ? (string)$params['full_name'] : null );
        $birthDate = Patient::normalizeNullableString($params['birth_date'] ?? null);
        $phone = Patient::normalizePhone( isset($params['phone']) ? (string)$params['phone'] : null );

        $hasValidIdentityInput = false;
        if ($fullName !== '' && $birthDate !== null) {
            $dt = DateTime::createFromFormat('Y-m-d', $birthDate);
            if ($dt && $dt->format('Y-m-d') === $birthDate) {
                $hasValidIdentityInput = true;
            }
        }

        if ($nik === null && !$hasValidIdentityInput) {
            return [
                'rc' => 422,
                'rm' => 'Masukkan NIK atau kombinasi full_name dan birth_date (YYYY-MM-DD) yang valid',
                'data' => null,
            ];
        }

        $conditions = ['or'];
        $bindParams = [];

        if ($nik !== null) {
            $conditions[] = 'nik = :nik';
            $bindParams[':nik'] = $nik;
        }

        if ($hasValidIdentityInput) {
            $conditions[] = "LOWER(full_name) = LOWER(:full_name) AND birth_date = :birth_date AND COALESCE(phone, '') = :phone";
            $bindParams[':full_name'] = $fullName;
            $bindParams[':birth_date'] = $birthDate;
            $bindParams[':phone'] = $phone ?? '';
        }

        $rows = (new Query())
            ->select(['id', 'medical_record_no', 'full_name', 'birth_date', 'phone'])
            ->from('patients')
            ->where(['deleted_at' => null])
            ->andWhere($conditions, $bindParams)
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id' => (int)$row['id'],
                'medical_record_no' => $row['medical_record_no'],
                'full_name' => $row['full_name'],
                'birth_date' => $row['birth_date'],
                'phone' => $row['phone'],
            ];
        }

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $data,
        ];
    }

    private function checkDuplicateConflict(Patient $patient, ?int $excludeId = null): ?array
    {
        if ($patient->nik !== null) {
            $existingNik = $this->findPatientByNik((string)$patient->nik, $excludeId);
            if ($existingNik !== null) {
                return [
                    'rc' => 409,
                    'rm' => "NIK sudah terdaftar atas pasien lain (No. RM: {$existingNik['medical_record_no']})",
                    'data' => null,
                ];
            }
        }

        $existingIdentity = $this->findPatientByIdentity(
            (string)$patient->full_name,
            (string)$patient->birth_date,
            $patient->phone,
            $excludeId
        );

        if ($existingIdentity !== null) {
            return [
                'rc' => 409,
                'rm' => "Pasien dengan nama, tanggal lahir, dan telepon yang sama sudah terdaftar (No. RM: {$existingIdentity['medical_record_no']})",
                'data' => null,
            ];
        }

        return null;
    }

    private function handleDbException(DbException $e, Patient $patient, ?int $excludeId = null): array
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'ck_patients_nik')) {
            return [
                'rc' => 422,
                'rm' => 'NIK harus terdiri dari tepat 16 digit angka',
                'data' => ['nik' => ['NIK harus terdiri dari tepat 16 digit angka']],
            ];
        }

        if (str_contains($msg, 'ux_patients_nik')) {
            $existing = $patient->nik !== null ? $this->findPatientByNik((string)$patient->nik, $excludeId) : null;
            $mr = $existing['medical_record_no'] ?? '-';
            return [
                'rc' => 409,
                'rm' => "NIK sudah terdaftar atas pasien lain (No. RM: {$mr})",
                'data' => null,
            ];
        }

        if (str_contains($msg, 'ux_patients_identity')) {
            $existing = $this->findPatientByIdentity(
                (string)$patient->full_name,
                (string)$patient->birth_date,
                $patient->phone,
                $excludeId
            );
            $mr = $existing['medical_record_no'] ?? '-';
            return [
                'rc' => 409,
                'rm' => "Pasien dengan nama, tanggal lahir, dan telepon yang sama sudah terdaftar (No. RM: {$mr})",
                'data' => null,
            ];
        }

        if (str_contains($msg, 'ux_patients_mr')) {
            Yii::$app->response->statusCode = 500;
            return [
                'rc' => 500,
                'rm' => 'Gagal menghasilkan nomor rekam medis unik',
                'data' => null,
            ];
        }

        throw $e;
    }

    private function findPatientByNik(string $nik, ?int $excludeId = null): ?array
    {
        $query = (new Query())
            ->select(['id', 'medical_record_no'])
            ->from('patients')
            ->where(['nik' => $nik, 'deleted_at' => null]);

        if ($excludeId !== null) {
            $query->andWhere(['!=', 'id', $excludeId]);
        }

        $row = $query->one();
        return $row !== false ? $row : null;
    }

    private function findPatientByIdentity(
        string $fullName,
        string $birthDate,
        ?string $phone,
        ?int $excludeId = null
    ): ?array {
        $sql = "
            SELECT id, medical_record_no
            FROM patients
            WHERE deleted_at IS NULL
              AND LOWER(full_name) = LOWER(:full_name)
              AND birth_date = :birth_date
              AND COALESCE(phone, '') = :phone
        ";

        $params = [
            ':full_name' => $fullName,
            ':birth_date' => $birthDate,
            ':phone' => $phone ?? '',
        ];

        if ($excludeId !== null) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $row = Yii::$app->db->createCommand($sql, $params)->queryOne();
        return $row !== false ? $row : null;
    }

    private function dataPatient(Patient $patient): array
    {
        return [
            'id' => (int)$patient->id,
            'medical_record_no' => $patient->medical_record_no,
            'nik' => $patient->nik,
            'full_name' => $patient->full_name,
            'gender' => $patient->gender,
            'birth_date' => $patient->birth_date,
            'age' => $patient->getAge(),
            'birth_place' => $patient->birth_place,
            'address' => $patient->address,
            'phone' => $patient->phone,
            'blood_type' => $patient->blood_type,
            'created_at' => $patient->created_at,
            'created_by' => $patient->created_by !== null ? (int)$patient->created_by : null,
            'updated_at' => $patient->updated_at,
            'updated_by' => $patient->updated_by !== null ? (int)$patient->updated_by : null,
        ];
    }
}
