<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\DoctorContext;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\AuditLog;
use app\models\Doctor;
use app\models\DoctorSchedule;
use app\models\Patient;
use app\models\Poli;
use app\models\Visit;
use DateTime;
use Throwable;
use yii\db\Exception as DbException;
use yii\db\Query;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class VisitsController extends Controller
{
    use MasterControllerTrait;

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
                    'index'  => ['GET'],
                    'view'   => ['GET'],
                    'create' => ['POST'],
                    'status' => ['PUT', 'PATCH'],
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
        $denied = $this->requireRoles(['ADMIN', 'REGISTRATION', 'DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $request = Yii::$app->request;
        $visitDateParam = $request->get('visit_date');
        $dateFrom = trim((string)$request->get('date_from', ''));
        $dateTo = trim((string)$request->get('date_to', ''));
        $poliId = $request->get('poli_id');
        $status = trim((string)$request->get('status', ''));
        $patientId = $request->get('patient_id');
        $q = trim((string)($request->get('q') ?? $request->get('search', '')));

        $doctorId = DoctorContext::resolveDoctorIdFilter($request->get('doctor_id'));

        $query = Visit::find()
            ->alias('v')
            ->with(['patient', 'poli', 'doctor', 'schedule'])
            ->leftJoin('{{%patients}} pt', 'pt.id = v.patient_id');

        if ($visitDateParam !== null && $visitDateParam !== '') {
            if (strtolower((string)$visitDateParam) !== 'all') {
                $query->andWhere(['v.visit_date' => trim((string)$visitDateParam)]);
            }
        } elseif ($dateFrom === '' && $dateTo === '') {
            $query->andWhere(['v.visit_date' => date('Y-m-d')]);
        }

        if ($dateFrom !== '') {
            $query->andWhere(['>=', 'v.visit_date', $dateFrom]);
        }

        if ($dateTo !== '') {
            $query->andWhere(['<=', 'v.visit_date', $dateTo]);
        }

        if ($poliId !== null && $poliId !== '') {
            $query->andWhere(['v.poli_id' => (int)$poliId]);
        }

        if ($doctorId !== null) {
            $query->andWhere(['v.doctor_id' => $doctorId]);
        }

        if ($status !== '') {
            $query->andWhere(['v.status' => strtoupper($status)]);
        }

        if ($patientId !== null && $patientId !== '') {
            $query->andWhere(['v.patient_id' => (int)$patientId]);
        }

        if ($q !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'v.visit_no', $q],
                ['ilike', 'pt.medical_record_no', $q],
                ['ilike', 'pt.full_name', $q],
            ]);
        }

        $orderBy = $this->resolveSortOrder([
            'queue_no'   => 'v.queue_no',
            'visit_date' => 'v.visit_date',
            'created_at' => 'v.created_at',
        ], [
            'v.visit_date' => SORT_DESC,
            'v.queue_no'   => SORT_ASC,
            'v.id'         => SORT_ASC,
        ]);

        return $this->buildPagedResponse($query, $orderBy, fn(Visit $v) => $this->dataVisit($v));
    }

    public function actionView(int $id): array
    {
        $denied = $this->requireRoles(['ADMIN', 'REGISTRATION', 'DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $visit = Visit::find()
            ->with(['patient', 'poli', 'doctor', 'schedule'])
            ->where(['id' => $id])
            ->one();

        if (!$visit) {
            return [
                'rc'   => 404,
                'rm'   => 'Data kunjungan tidak ditemukan',
                'data' => null,
            ];
        }

        $ownCheck = DoctorContext::assertOwnsVisit((int)$visit->doctor_id);
        if ($ownCheck !== null) {
            return $ownCheck;
        }

        AuditLog::log('visits', $id, 'VIEW');

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->dataVisit($visit),
        ];
    }

    public function actionCreate(): array
    {
        $denied = $this->requireRoles(['ADMIN', 'REGISTRATION']);
        if ($denied !== null) {
            return $denied;
        }

        $params = $this->getRequestParams();
        $patientIdRaw = $params['patient_id'] ?? null;
        $poliIdRaw = $params['poli_id'] ?? null;
        $doctorIdRaw = $params['doctor_id'] ?? null;
        $visitDateRaw = isset($params['visit_date']) && trim((string)$params['visit_date']) !== ''
            ? trim((string)$params['visit_date'])
            : date('Y-m-d');

        if ($patientIdRaw === null || $patientIdRaw === '' || !is_numeric($patientIdRaw)) {
            return [
                'rc'   => 422,
                'rm'   => 'patient_id wajib diisi',
                'data' => ['patient_id' => ['patient_id wajib diisi']],
            ];
        }

        if ($poliIdRaw === null || $poliIdRaw === '' || !is_numeric($poliIdRaw)) {
            return [
                'rc'   => 422,
                'rm'   => 'poli_id wajib diisi',
                'data' => ['poli_id' => ['poli_id wajib diisi']],
            ];
        }

        if ($doctorIdRaw === null || $doctorIdRaw === '' || !is_numeric($doctorIdRaw)) {
            return [
                'rc'   => 422,
                'rm'   => 'doctor_id wajib diisi',
                'data' => ['doctor_id' => ['doctor_id wajib diisi']],
            ];
        }

        $dt = DateTime::createFromFormat('Y-m-d', $visitDateRaw);
        if (!$dt || $dt->format('Y-m-d') !== $visitDateRaw) {
            return [
                'rc'   => 422,
                'rm'   => 'Format visit_date harus YYYY-MM-DD yang valid',
                'data' => ['visit_date' => ['Format visit_date harus YYYY-MM-DD yang valid']],
            ];
        }

        $todayStr = date('Y-m-d');
        $todayDt = new DateTime($todayStr);
        $maxDt = (new DateTime($todayStr))->modify('+7 days');
        $visitDt = new DateTime($visitDateRaw);

        if ($visitDt < $todayDt) {
            return [
                'rc'   => 422,
                'rm'   => 'Tanggal kunjungan tidak boleh di masa lalu',
                'data' => ['visit_date' => ['Tanggal kunjungan tidak boleh di masa lalu']],
            ];
        }

        if ($visitDt > $maxDt) {
            return [
                'rc'   => 422,
                'rm'   => 'Tanggal kunjungan maksimal 7 hari ke depan',
                'data' => ['visit_date' => ['Tanggal kunjungan maksimal 7 hari ke depan']],
            ];
        }

        $patientId = (int)$patientIdRaw;
        $poliId = (int)$poliIdRaw;
        $doctorId = (int)$doctorIdRaw;
        $dayOfWeek = (int)$visitDt->format('N');

        return $this->executeRegistrationTransaction($patientId, $poliId, $doctorId, $visitDateRaw, $dayOfWeek, $todayStr);
    }

    public function actionStatus(int $id): array
    {
        $visit = Visit::find()
            ->with(['patient', 'poli', 'doctor', 'schedule'])
            ->where(['id' => $id])
            ->one();

        if (!$visit) {
            return [
                'rc'   => 404,
                'rm'   => 'Data kunjungan tidak ditemukan',
                'data' => null,
            ];
        }

        $params = $this->getRequestParams();
        $newStatus = strtoupper(trim((string)($params['status'] ?? '')));
        $reason = trim((string)($params['reason'] ?? ''));

        if (in_array($newStatus, [Visit::STATUS_EXAMINING, Visit::STATUS_DONE], true)) {
            return [
                'rc'   => 422,
                'rm'   => 'Gunakan modul pemeriksaan',
                'data' => ['status' => ['Gunakan modul pemeriksaan']],
            ];
        }

        if (!in_array($newStatus, [Visit::STATUS_CALLED, Visit::STATUS_WAITING, Visit::STATUS_CANCELLED], true)) {
            return [
                'rc'   => 422,
                'rm'   => 'Status harus salah satu dari: CALLED, WAITING, CANCELLED',
                'data' => ['status' => ['Status harus salah satu dari: CALLED, WAITING, CANCELLED']],
            ];
        }

        if ($newStatus === Visit::STATUS_CANCELLED) {
            $denied = $this->requireRoles(['ADMIN', 'REGISTRATION']);
            if ($denied !== null) {
                return $denied;
            }

            if (mb_strlen($reason) < 5) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Alasan pembatalan (reason) wajib diisi minimal 5 karakter',
                    'data' => ['reason' => ['Alasan pembatalan wajib diisi minimal 5 karakter']],
                ];
            }
        } else {
            $denied = $this->requireRoles(['ADMIN', 'REGISTRATION', 'DOCTOR']);
            if ($denied !== null) {
                return $denied;
            }

            $ownCheck = DoctorContext::assertOwnsVisit((int)$visit->doctor_id);
            if ($ownCheck !== null) {
                return $ownCheck;
            }
        }

        $oldStatus = (string)$visit->status;

        if (in_array($oldStatus, [Visit::STATUS_DONE, Visit::STATUS_CANCELLED, Visit::STATUS_EXAMINING], true)) {
            return [
                'rc'   => 409,
                'rm'   => "Kunjungan berstatus {$oldStatus} tidak dapat diubah ke {$newStatus}",
                'data' => null,
            ];
        }

        $allowedTransitions = [
            Visit::STATUS_WAITING => [Visit::STATUS_CALLED, Visit::STATUS_CANCELLED],
            Visit::STATUS_CALLED  => [Visit::STATUS_WAITING, Visit::STATUS_CANCELLED],
        ];

        if (!isset($allowedTransitions[$oldStatus]) || !in_array($newStatus, $allowedTransitions[$oldStatus], true)) {
            return [
                'rc'   => 409,
                'rm'   => "Transisi status dari {$oldStatus} ke {$newStatus} tidak diizinkan",
                'data' => null,
            ];
        }

        $visit->status = $newStatus;
        $visit->updated_at = date('Y-m-d H:i:s');
        $visit->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;
        $visit->save(false);

        AuditLog::log(
            'visits',
            (int)$visit->id,
            'STATUS_CHANGE',
            ['status' => $oldStatus],
            ['status' => $newStatus],
            $reason !== '' ? $reason : null
        );

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->dataVisit($visit),
        ];
    }

    private function executeRegistrationTransaction(
        int $patientId,
        int $poliId,
        int $doctorId,
        string $visitDate,
        int $dayOfWeek,
        string $todayStr,
        int $attempt = 1
    ): array {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            $patient = Patient::findOne(['id' => $patientId, 'deleted_at' => null]);
            if (!$patient) {
                $transaction->rollBack();
                return [
                    'rc'   => 422,
                    'rm'   => 'Data pasien tidak valid atau sudah dihapus',
                    'data' => null,
                ];
            }

            $poli = Poli::findOne(['id' => $poliId, 'is_active' => true, 'deleted_at' => null]);
            if (!$poli) {
                $transaction->rollBack();
                return [
                    'rc'   => 422,
                    'rm'   => 'Data poli tidak ditemukan atau tidak aktif',
                    'data' => null,
                ];
            }

            $doctor = Doctor::findOne(['id' => $doctorId, 'is_active' => true, 'deleted_at' => null]);
            if (!$doctor) {
                $transaction->rollBack();
                return [
                    'rc'   => 422,
                    'rm'   => 'Data dokter tidak ditemukan atau tidak aktif',
                    'data' => null,
                ];
            }

            $schedules = DoctorSchedule::find()
                ->where([
                    'doctor_id'   => $doctorId,
                    'poli_id'     => $poliId,
                    'day_of_week' => $dayOfWeek,
                    'is_active'   => true,
                ])
                ->orderBy(['start_time' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            if (empty($schedules)) {
                $transaction->rollBack();
                return [
                    'rc'   => 422,
                    'rm'   => 'Dokter tidak memiliki jadwal praktik pada hari tersebut di poli ini',
                    'data' => null,
                ];
            }

            $selectedSchedule = null;
            if ($visitDate === $todayStr) {
                $nowSec = strtotime('1970-01-01 ' . date('H:i:s'));
                foreach ($schedules as $sch) {
                    $endSec = strtotime('1970-01-01 ' . $sch->end_time);
                    if ($endSec >= $nowSec) {
                        $selectedSchedule = $sch;
                        break;
                    }
                }
                if ($selectedSchedule === null) {
                    $transaction->rollBack();
                    return [
                        'rc'   => 422,
                        'rm'   => 'Jadwal praktik hari ini sudah berakhir',
                        'data' => null,
                    ];
                }
            } else {
                $selectedSchedule = $schedules[0];
            }

            $queueNo = (int)$db->createCommand("
                INSERT INTO queue_counters (poli_id, queue_date, last_no)
                VALUES (:poli, :qdate, 1)
                ON CONFLICT (poli_id, queue_date)
                DO UPDATE SET last_no = queue_counters.last_no + 1
                RETURNING last_no
            ", [
                ':poli'  => $poliId,
                ':qdate' => $visitDate,
            ])->queryScalar();

            $bookedCount = (int)(new Query())
                ->from('visits')
                ->where([
                    'schedule_id' => (int)$selectedSchedule->id,
                    'visit_date'  => $visitDate,
                ])
                ->andWhere(['<>', 'status', Visit::STATUS_CANCELLED])
                ->count();

            if ($bookedCount >= (int)$selectedSchedule->quota) {
                $transaction->rollBack();
                return [
                    'rc'   => 409,
                    'rm'   => 'Kuota dokter pada hari ini sudah penuh',
                    'data' => null,
                ];
            }

            $existingDupQueue = (new Query())
                ->select('queue_no')
                ->from('visits')
                ->where([
                    'patient_id' => $patientId,
                    'doctor_id'  => $doctorId,
                    'visit_date' => $visitDate,
                ])
                ->andWhere(['<>', 'status', Visit::STATUS_CANCELLED])
                ->scalar();

            if ($existingDupQueue !== false && $existingDupQueue !== null) {
                $transaction->rollBack();
                return [
                    'rc'   => 409,
                    'rm'   => "Pasien sudah terdaftar pada dokter dan tanggal yang sama (antrean no. {$existingDupQueue})",
                    'data' => null,
                ];
            }

            $dateCompact = str_replace('-', '', $visitDate);
            $seqVal = (int)$db->createCommand("SELECT nextval('visit_no_seq')")->queryScalar();
            $visitNo = 'V' . $dateCompact . '-' . str_pad((string)$seqVal, 6, '0', STR_PAD_LEFT);

            $now = date('Y-m-d H:i:s');
            $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

            $visit = new Visit();
            $visit->visit_no = $visitNo;
            $visit->patient_id = $patientId;
            $visit->poli_id = $poliId;
            $visit->doctor_id = $doctorId;
            $visit->schedule_id = (int)$selectedSchedule->id;
            $visit->visit_date = $visitDate;
            $visit->queue_no = $queueNo;
            $visit->status = Visit::STATUS_WAITING;
            $visit->registration_fee = (float)$poli->registration_fee;
            $visit->created_at = $now;
            $visit->created_by = $currentUserId;
            $visit->updated_at = $now;
            $visit->updated_by = $currentUserId;

            $visit->save(false);

            $visit->populateRelation('patient', $patient);
            $visit->populateRelation('poli', $poli);
            $visit->populateRelation('doctor', $doctor);
            $visit->populateRelation('schedule', $selectedSchedule);

            $data = $this->dataVisit($visit);

            AuditLog::log('visits', (int)$visit->id, 'CREATE', null, [
                'visit_no'         => $visit->visit_no,
                'patient_id'       => $visit->patient_id,
                'poli_id'          => $visit->poli_id,
                'doctor_id'        => $visit->doctor_id,
                'schedule_id'      => $visit->schedule_id,
                'visit_date'       => $visit->visit_date,
                'queue_no'         => $visit->queue_no,
                'status'           => $visit->status,
                'registration_fee' => $visit->registration_fee,
            ]);

            $transaction->commit();

            return [
                'rc'   => 200,
                'rm'   => 'Berhasil',
                'data' => $data,
            ];
        } catch (Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }

            if ($e instanceof DbException) {
                $msg = $e->getMessage();
                $sqlState = $e->errorInfo[0] ?? '';

                if (str_contains($msg, 'ux_visits_no_dup')) {
                    $existingQ = (new Query())
                        ->select('queue_no')
                        ->from('visits')
                        ->where([
                            'patient_id' => $patientId,
                            'doctor_id'  => $doctorId,
                            'visit_date' => $visitDate,
                        ])
                        ->andWhere(['<>', 'status', Visit::STATUS_CANCELLED])
                        ->scalar();

                    $qDisplay = ($existingQ !== false && $existingQ !== null) ? $existingQ : '-';
                    return [
                        'rc'   => 409,
                        'rm'   => "Pasien sudah terdaftar pada dokter dan tanggal yang sama (antrean no. {$qDisplay})",
                        'data' => null,
                    ];
                }

                if (str_contains($msg, 'ux_visits_queue')) {
                    if ($attempt < 2) {
                        return $this->executeRegistrationTransaction(
                            $patientId,
                            $poliId,
                            $doctorId,
                            $visitDate,
                            $dayOfWeek,
                            $todayStr,
                            $attempt + 1
                        );
                    }
                    Yii::$app->response->statusCode = 500;
                    return [
                        'rc'   => 500,
                        'rm'   => 'Kegagalan internal saat menghasilkan nomor antrean',
                        'data' => null,
                    ];
                }

                if ($sqlState === '23503') {
                    return [
                        'rc'   => 422,
                        'rm'   => 'Data pasien/poli/dokter tidak valid',
                        'data' => null,
                    ];
                }
            }

            throw $e;
        }
    }

    private function dataVisit(Visit $visit): array
    {
        $patientData = null;
        if ($visit->patient) {
            $patientData = [
                'id'                => (int)$visit->patient->id,
                'medical_record_no' => $visit->patient->medical_record_no,
                'full_name'         => $visit->patient->full_name,
                'age'               => $visit->patient->getAge(),
            ];
        }

        $poliData = null;
        if ($visit->poli) {
            $poliData = [
                'id'               => (int)$visit->poli->id,
                'code'             => $visit->poli->code,
                'name'             => $visit->poli->name,
                'registration_fee' => (float)$visit->poli->registration_fee,
            ];
        }

        $doctorData = null;
        if ($visit->doctor) {
            $doctorData = [
                'id'        => (int)$visit->doctor->id,
                'full_name' => $visit->doctor->full_name,
                'specialty' => $visit->doctor->specialty,
            ];
        }

        $scheduleData = null;
        if ($visit->schedule) {
            $scheduleData = [
                'id'          => (int)$visit->schedule->id,
                'day_of_week' => (int)$visit->schedule->day_of_week,
                'start_time'  => $visit->schedule->start_time,
                'end_time'    => $visit->schedule->end_time,
            ];
        }

        return [
            'id'               => (int)$visit->id,
            'visit_no'         => $visit->visit_no,
            'patient_id'       => (int)$visit->patient_id,
            'patient'          => $patientData,
            'poli_id'          => (int)$visit->poli_id,
            'poli'             => $poliData,
            'doctor_id'        => (int)$visit->doctor_id,
            'doctor'           => $doctorData,
            'schedule_id'      => $visit->schedule_id !== null ? (int)$visit->schedule_id : null,
            'schedule'         => $scheduleData,
            'visit_date'       => $visit->visit_date,
            'queue_no'         => (int)$visit->queue_no,
            'status'           => $visit->status,
            'registration_fee' => (float)$visit->registration_fee,
            'created_at'       => $visit->created_at,
            'created_by'       => $visit->created_by !== null ? (int)$visit->created_by : null,
            'updated_at'       => $visit->updated_at,
            'updated_by'       => $visit->updated_by !== null ? (int)$visit->updated_by : null,
        ];
    }
}
