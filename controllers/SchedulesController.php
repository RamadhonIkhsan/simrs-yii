<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\models\AuditLog;
use app\models\Doctor;
use app\models\DoctorSchedule;
use app\models\Poli;
use DateTime;
use yii\db\Exception as DbException;
use yii\db\Expression;
use yii\db\Query;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class SchedulesController extends Controller
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
                    'index'     => ['GET'],
                    'available' => ['GET'],
                    'view'      => ['GET'],
                    'create'    => ['POST'],
                    'update'    => ['PUT', 'PATCH'],
                    'delete'    => ['DELETE'],
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
        $doctorId = $request->get('doctor_id');
        $poliId = $request->get('poli_id');
        $dayOfWeek = $request->get('day_of_week');
        $isActive = $request->get('is_active');
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

        $query = DoctorSchedule::find()
            ->alias('s')
            ->with(['doctor', 'poli'])
            ->leftJoin('{{%doctors}} d', 'd.id = s.doctor_id')
            ->leftJoin('{{%polis}} p', 'p.id = s.poli_id');

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'd.full_name', $search],
                ['ilike', 'p.name', $search],
            ]);
        }

        if ($doctorId !== null && $doctorId !== '') {
            $query->andWhere(['s.doctor_id' => (int)$doctorId]);
        }

        if ($poliId !== null && $poliId !== '') {
            $query->andWhere(['s.poli_id' => (int)$poliId]);
        }

        if ($dayOfWeek !== null && $dayOfWeek !== '') {
            $query->andWhere(['s.day_of_week' => (int)$dayOfWeek]);
        }

        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['s.is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        $allowedSort = [
            'day_of_week' => 's.day_of_week',
            'start_time'  => 's.start_time',
            'created_at'  => 's.created_at',
        ];

        $orderBy = [
            's.day_of_week' => SORT_ASC,
            's.start_time'  => SORT_ASC,
            's.id'          => SORT_ASC,
        ];

        if ($sortParam !== null && is_string($sortParam)) {
            $isDesc = str_starts_with($sortParam, '-') || $orderParam === 'desc';
            $cleanSort = ltrim($sortParam, '-');
            if (isset($allowedSort[$cleanSort])) {
                $orderBy = [
                    $allowedSort[$cleanSort] => $isDesc ? SORT_DESC : SORT_ASC,
                    's.id' => SORT_ASC,
                ];
            }
        }

        $totalElements = (int)(clone $query)->count();
        $totalPages = $size > 0 ? (int)ceil($totalElements / $size) : 0;

        $schedules = $query->orderBy($orderBy)
            ->limit($size)
            ->offset($offset)
            ->all();

        $data = [];
        foreach ($schedules as $schedule) {
            $data[] = $this->dataSchedule($schedule);
        }

        return [
            'page' => $displayPage,
            'size' => $size,
            'totalPages' => $totalPages,
            'totalElements' => $totalElements,
            'data' => $data,
        ];
    }

    public function actionAvailable(): array
    {
        $request = Yii::$app->request;
        $poliIdParam = $request->get('poli_id');
        $dateParam = trim((string)$request->get('date', ''));
        $dayOfWeekParam = $request->get('day_of_week');

        if ($poliIdParam === null || $poliIdParam === '' || !is_numeric($poliIdParam) || (int)$poliIdParam <= 0) {
            return [
                'rc' => 422,
                'rm' => 'Parameter poli_id wajib diisi',
                'data' => null,
            ];
        }

        $poliId = (int)$poliIdParam;

        if ($dateParam === '' && ($dayOfWeekParam === null || $dayOfWeekParam === '')) {
            return [
                'rc' => 422,
                'rm' => 'Parameter date (YYYY-MM-DD) atau day_of_week (1-7) wajib diisi',
                'data' => null,
            ];
        }

        if ($dateParam !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $dateParam);
            if (!$dt || $dt->format('Y-m-d') !== $dateParam) {
                return [
                    'rc' => 422,
                    'rm' => 'Format date harus YYYY-MM-DD yang valid',
                    'data' => null,
                ];
            }
            $dayOfWeek = (int)$dt->format('N');
            $hasDate = true;
        } else {
            if (!is_numeric($dayOfWeekParam) || (int)$dayOfWeekParam < 1 || (int)$dayOfWeekParam > 7) {
                return [
                    'rc' => 422,
                    'rm' => 'Parameter day_of_week harus bernilai antara 1 sampai 7',
                    'data' => null,
                ];
            }
            $dayOfWeek = (int)$dayOfWeekParam;
            $hasDate = false;
        }

        $query = (new Query())
            ->from(['s' => 'doctor_schedules'])
            ->innerJoin(['d' => 'doctors'], 'd.id = s.doctor_id AND d.is_active = true')
            ->innerJoin(['p' => 'polis'], 'p.id = s.poli_id AND p.is_active = true')
            ->where([
                's.poli_id' => $poliId,
                's.day_of_week' => $dayOfWeek,
                's.is_active' => true,
            ])
            ->orderBy(['s.start_time' => SORT_ASC, 's.id' => SORT_ASC]);

        if ($hasDate) {
            $query->select([
                'schedule_id' => 's.id',
                's.doctor_id',
                'doctor_name' => 'd.full_name',
                'd.specialty',
                's.poli_id',
                'poli_name' => 'p.name',
                's.day_of_week',
                's.start_time',
                's.end_time',
                's.quota',
                'booked' => new Expression('COUNT(v.id)'),
            ])
            ->leftJoin(
                ['v' => 'visits'],
                "v.schedule_id = s.id AND v.visit_date = :visit_date AND v.status <> 'CANCELLED'",
                [':visit_date' => $dateParam]
            )
            ->groupBy([
                's.id',
                's.doctor_id',
                'd.full_name',
                'd.specialty',
                's.poli_id',
                'p.name',
                's.day_of_week',
                's.start_time',
                's.end_time',
                's.quota',
            ]);
        } else {
            $query->select([
                'schedule_id' => 's.id',
                's.doctor_id',
                'doctor_name' => 'd.full_name',
                'd.specialty',
                's.poli_id',
                'poli_name' => 'p.name',
                's.day_of_week',
                's.start_time',
                's.end_time',
                's.quota',
            ]);
        }

        $rows = $query->all();

        $data = [];
        foreach ($rows as $row) {
            $quota = (int)$row['quota'];
            $booked = $hasDate ? (int)$row['booked'] : null;
            $remaining = $hasDate ? max($quota - $booked, 0) : null;

            $data[] = [
                'schedule_id' => (int)$row['schedule_id'],
                'doctor_id' => (int)$row['doctor_id'],
                'doctor_name' => $row['doctor_name'],
                'specialty' => $row['specialty'],
                'poli_id' => (int)$row['poli_id'],
                'poli_name' => $row['poli_name'],
                'day_of_week' => (int)$row['day_of_week'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'quota' => $quota,
                'booked' => $booked,
                'remaining' => $remaining,
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
        $schedule = DoctorSchedule::find()
            ->with(['doctor', 'poli'])
            ->where(['id' => $id])
            ->one();

        if (!$schedule) {
            return [
                'rc' => 404,
                'rm' => 'Data jadwal tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('doctor_schedules', $id, 'VIEW');

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $this->dataSchedule($schedule),
        ];
    }

    public function actionCreate(): array
    {
        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        $doctorId = isset($params['doctor_id']) && $params['doctor_id'] !== '' ? (int)$params['doctor_id'] : null;
        $poliId = isset($params['poli_id']) && $params['poli_id'] !== '' ? (int)$params['poli_id'] : null;

        if ($doctorId !== null) {
            $doctorCheck = $this->validateDoctor($doctorId);
            if ($doctorCheck !== null) {
                return $doctorCheck;
            }
        }

        if ($poliId !== null) {
            $poliCheck = $this->validatePoli($poliId);
            if ($poliCheck !== null) {
                return $poliCheck;
            }
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $schedule = new DoctorSchedule();
        $schedule->doctor_id = $doctorId;
        $schedule->poli_id = $poliId;
        $schedule->day_of_week = isset($params['day_of_week']) && $params['day_of_week'] !== '' ? (int)$params['day_of_week'] : null;
        $schedule->start_time = isset($params['start_time']) ? trim((string)$params['start_time']) : null;
        $schedule->end_time = isset($params['end_time']) ? trim((string)$params['end_time']) : null;
        $schedule->quota = isset($params['quota']) && $params['quota'] !== '' ? (int)$params['quota'] : 30;
        $schedule->is_active = isset($params['is_active']) ? filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN) : true;
        $schedule->created_at = $now;
        $schedule->created_by = $currentUserId;
        $schedule->updated_at = $now;
        $schedule->updated_by = $currentUserId;

        if (!$schedule->validate()) {
            return $this->formatValidationErrors($schedule);
        }

        try {
            $schedule->save(false);
        } catch (DbException $e) {
            return $this->handleDbException($e, $schedule);
        }

        $schedule->refresh();
        $data = $this->dataSchedule($schedule);
        AuditLog::log('doctor_schedules', (int)$schedule->id, 'CREATE', null, $data);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionUpdate(int $id): array
    {
        $schedule = DoctorSchedule::find()
            ->with(['doctor', 'poli'])
            ->where(['id' => $id])
            ->one();

        if (!$schedule) {
            return [
                'rc' => 404,
                'rm' => 'Data jadwal tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataSchedule($schedule);

        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        if (isset($params['doctor_id']) && $params['doctor_id'] !== '') {
            $newDoctorId = (int)$params['doctor_id'];
            $doctorCheck = $this->validateDoctor($newDoctorId);
            if ($doctorCheck !== null) {
                return $doctorCheck;
            }
            $schedule->doctor_id = $newDoctorId;
        }

        if (isset($params['poli_id']) && $params['poli_id'] !== '') {
            $newPoliId = (int)$params['poli_id'];
            $poliCheck = $this->validatePoli($newPoliId);
            if ($poliCheck !== null) {
                return $poliCheck;
            }
            $schedule->poli_id = $newPoliId;
        }

        if (isset($params['day_of_week']) && $params['day_of_week'] !== '') {
            $schedule->day_of_week = (int)$params['day_of_week'];
        }

        if (isset($params['start_time'])) {
            $schedule->start_time = trim((string)$params['start_time']);
        }

        if (isset($params['end_time'])) {
            $schedule->end_time = trim((string)$params['end_time']);
        }

        if (isset($params['quota']) && $params['quota'] !== '') {
            $schedule->quota = (int)$params['quota'];
        }

        if (isset($params['is_active'])) {
            $schedule->is_active = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $schedule->updated_at = date('Y-m-d H:i:s');
        $schedule->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        if (!$schedule->validate()) {
            return $this->formatValidationErrors($schedule);
        }

        try {
            $schedule->save(false);
        } catch (DbException $e) {
            return $this->handleDbException($e, $schedule);
        }

        $schedule->refresh();
        $newValues = $this->dataSchedule($schedule);
        AuditLog::log('doctor_schedules', (int)$schedule->id, 'UPDATE', $oldValues, $newValues);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $newValues,
        ];
    }

    public function actionDelete(int $id): array
    {
        $schedule = DoctorSchedule::find()
            ->with(['doctor', 'poli'])
            ->where(['id' => $id])
            ->one();

        if (!$schedule) {
            return [
                'rc' => 404,
                'rm' => 'Data jadwal tidak ditemukan',
                'data' => null,
            ];
        }

        $hasVisits = (new Query())
            ->from('visits')
            ->where(['schedule_id' => $id])
            ->exists();

        if ($hasVisits) {
            return [
                'rc' => 409,
                'rm' => 'Jadwal sudah memiliki riwayat kunjungan, silakan nonaktifkan jadwal (is_active=false)',
                'data' => null,
            ];
        }

        $oldValues = $this->dataSchedule($schedule);

        try {
            $schedule->delete();
        } catch (DbException $e) {
            if (str_contains($e->getMessage(), 'visits_schedule_id_fkey')) {
                return [
                    'rc' => 409,
                    'rm' => 'Jadwal sudah memiliki riwayat kunjungan, silakan nonaktifkan jadwal (is_active=false)',
                    'data' => null,
                ];
            }
            throw $e;
        }

        AuditLog::log('doctor_schedules', $id, 'DELETE', $oldValues, null);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => null,
        ];
    }

    private function validateDoctor(int $doctorId): ?array
    {
        $exists = Doctor::find()
            ->where(['id' => $doctorId, 'is_active' => true])
            ->exists();

        if (!$exists) {
            return [
                'rc' => 422,
                'rm' => 'Dokter tidak ditemukan atau tidak aktif',
                'data' => null,
            ];
        }

        return null;
    }

    private function validatePoli(int $poliId): ?array
    {
        $exists = Poli::find()
            ->where(['id' => $poliId, 'is_active' => true])
            ->exists();

        if (!$exists) {
            return [
                'rc' => 422,
                'rm' => 'Poli tidak ditemukan atau tidak aktif',
                'data' => null,
            ];
        }

        return null;
    }

    private function formatValidationErrors(DoctorSchedule $schedule): array
    {
        $errors = $schedule->getErrors();
        if (isset($errors['end_time'])) {
            foreach ($errors['end_time'] as $err) {
                if (str_contains($err, 'Jam selesai harus lebih besar dari jam mulai')) {
                    return [
                        'rc' => 422,
                        'rm' => 'Jam selesai harus lebih besar dari jam mulai',
                        'data' => $errors,
                    ];
                }
            }
        }

        return [
            'rc' => 422,
            'rm' => 'Validasi gagal',
            'data' => $errors,
        ];
    }

    private function handleDbException(DbException $e, DoctorSchedule $schedule): array
    {
        $msg = $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? '';

        if (str_contains($msg, 'ck_schedule_time')) {
            return [
                'rc' => 422,
                'rm' => 'Jam selesai harus lebih besar dari jam mulai',
                'data' => null,
            ];
        }

        if ($sqlState === '23P01' || str_contains($msg, 'ex_schedule_overlap')) {
            $conflict = $this->findConflictingSchedule(
                (int)$schedule->doctor_id,
                (int)$schedule->day_of_week,
                (string)$schedule->start_time,
                (string)$schedule->end_time,
                $schedule->id !== null ? (int)$schedule->id : null
            );

            if ($conflict !== null) {
                $dayName = DoctorSchedule::getDayName((int)$conflict['day_of_week']);
                return [
                    'rc' => 409,
                    'rm' => "Jadwal bentrok dengan jadwal dokter ini pada hari {$dayName} ({$conflict['start_hm']}-{$conflict['end_hm']})",
                    'data' => null,
                ];
            }

            return [
                'rc' => 409,
                'rm' => 'Jadwal bentrok dengan jadwal dokter ini pada hari yang sama',
                'data' => null,
            ];
        }

        throw $e;
    }

    private function findConflictingSchedule(
        int $doctorId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        ?int $excludeId = null
    ): ?array {
        $sql = "
            SELECT id, day_of_week,
                   to_char(start_time, 'HH24:MI') AS start_hm,
                   to_char(end_time, 'HH24:MI') AS end_hm
            FROM doctor_schedules
            WHERE doctor_id = :doctor_id
              AND day_of_week = :day_of_week
              AND is_active = true
              AND int4range(
                    (EXTRACT(hour FROM start_time)::int * 60 + EXTRACT(minute FROM start_time)::int),
                    (EXTRACT(hour FROM end_time)::int * 60 + EXTRACT(minute FROM end_time)::int)
                  ) && int4range(
                    (EXTRACT(hour FROM CAST(:start_time AS time))::int * 60 + EXTRACT(minute FROM CAST(:start_time AS time))::int),
                    (EXTRACT(hour FROM CAST(:end_time AS time))::int * 60 + EXTRACT(minute FROM CAST(:end_time AS time))::int)
                  )
        ";

        $params = [
            ':doctor_id' => $doctorId,
            ':day_of_week' => $dayOfWeek,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
        ];

        if ($excludeId !== null) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $sql .= " ORDER BY start_time ASC LIMIT 1";

        $row = Yii::$app->db->createCommand($sql, $params)->queryOne();
        return $row !== false ? $row : null;
    }

    private function dataSchedule(DoctorSchedule $schedule): array
    {
        $doctorData = null;
        if ($schedule->doctor) {
            $doctorData = [
                'id' => (int)$schedule->doctor->id,
                'full_name' => $schedule->doctor->full_name,
                'specialty' => $schedule->doctor->specialty,
            ];
        }

        $poliData = null;
        if ($schedule->poli) {
            $poliData = [
                'id' => (int)$schedule->poli->id,
                'code' => $schedule->poli->code,
                'name' => $schedule->poli->name,
            ];
        }

        $dayOfWeek = (int)$schedule->day_of_week;

        return [
            'id' => (int)$schedule->id,
            'doctor_id' => (int)$schedule->doctor_id,
            'doctor' => $doctorData,
            'poli_id' => (int)$schedule->poli_id,
            'poli' => $poliData,
            'day_of_week' => $dayOfWeek,
            'day_name' => DoctorSchedule::getDayName($dayOfWeek),
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'quota' => (int)$schedule->quota,
            'is_active' => (bool)$schedule->is_active,
            'created_at' => $schedule->created_at,
            'created_by' => $schedule->created_by !== null ? (int)$schedule->created_by : null,
            'updated_at' => $schedule->updated_at,
            'updated_by' => $schedule->updated_by !== null ? (int)$schedule->updated_by : null,
        ];
    }
}
