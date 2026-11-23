<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\DoctorContext;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\AuditLog;
use app\models\Diagnosis;
use app\models\Examination;
use app\models\ExaminationRevision;
use app\models\Icd10;
use app\models\Visit;
use app\models\VitalSign;
use DateTime;
use Throwable;
use yii\db\Exception as DbException;
use yii\db\Query;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class ExaminationsController extends Controller
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
                    'waiting'         => ['GET'],
                    'start'           => ['POST'],
                    'view'            => ['GET'],
                    'update'          => ['PUT', 'PATCH'],
                    'finalize'        => ['POST'],
                    'revise'          => ['POST'],
                    'revisions'       => ['GET'],
                    'revision-detail' => ['GET'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function actionWaiting(): array
    {
        $denied = $this->requireRoles(['DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $dateParam = trim((string)Yii::$app->request->get('date', ''));
        $date = $dateParam !== '' ? $dateParam : date('Y-m-d');

        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            return [
                'rc'   => 422,
                'rm'   => 'Format date harus YYYY-MM-DD yang valid',
                'data' => null,
            ];
        }

        $doctorId = DoctorContext::getCurrentDoctorId() ?? -1;

        $rows = (new Query())
            ->select([
                'visit_id'           => 'v.id',
                'v.visit_no',
                'v.queue_no',
                'v.status',
                'v.patient_id',
                'medical_record_no'  => 'pt.medical_record_no',
                'patient_name'       => 'pt.full_name',
                'patient_gender'     => 'pt.gender',
                'patient_birth_date' => 'pt.birth_date',
                'v.poli_id',
                'poli_code'          => 'p.code',
                'poli_name'          => 'p.name',
                'examination_id'     => 'e.id',
                'examination_status' => 'e.status',
            ])
            ->from(['v' => 'visits'])
            ->innerJoin(['pt' => 'patients'], 'pt.id = v.patient_id')
            ->innerJoin(['p' => 'polis'], 'p.id = v.poli_id')
            ->leftJoin(['e' => 'examinations'], 'e.visit_id = v.id')
            ->where([
                'v.doctor_id'  => $doctorId,
                'v.visit_date' => $date,
            ])
            ->andWhere(['in', 'v.status', [
                Visit::STATUS_WAITING,
                Visit::STATUS_CALLED,
                Visit::STATUS_EXAMINING,
            ]])
            ->orderBy(['v.queue_no' => SORT_ASC, 'v.id' => SORT_ASC])
            ->all();

        $today = new DateTime('today');
        $data = [];

        foreach ($rows as $r) {
            $age = null;
            if (!empty($r['patient_birth_date'])) {
                $bdt = DateTime::createFromFormat('Y-m-d', (string)$r['patient_birth_date']);
                if ($bdt) {
                    $age = $bdt->diff($today)->y;
                }
            }

            $data[] = [
                'queue_no'           => (int)$r['queue_no'],
                'status'             => $r['status'],
                'visit_id'           => (int)$r['visit_id'],
                'visit_no'           => $r['visit_no'],
                'patient'            => [
                    'id'                => (int)$r['patient_id'],
                    'medical_record_no' => $r['medical_record_no'],
                    'full_name'         => $r['patient_name'],
                    'age'               => $age,
                    'gender'            => $r['patient_gender'],
                ],
                'poli'               => [
                    'id'   => (int)$r['poli_id'],
                    'code' => $r['poli_code'],
                    'name' => $r['poli_name'],
                ],
                'has_examination'    => $r['examination_id'] !== null,
                'examination_id'     => $r['examination_id'] !== null ? (int)$r['examination_id'] : null,
                'examination_status' => $r['examination_status'],
            ];
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionStart(int $id): array
    {
        $denied = $this->requireRoles(['DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $visit = Visit::findOne(['id' => $id]);
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

        if (!in_array((string)$visit->status, [
            Visit::STATUS_WAITING,
            Visit::STATUS_CALLED,
            Visit::STATUS_EXAMINING,
        ], true)) {
            return [
                'rc'   => 409,
                'rm'   => "Pemeriksaan tidak dapat dimulai pada kunjungan berstatus {$visit->status}",
                'data' => null,
            ];
        }

        $existing = Examination::findOne(['visit_id' => $id]);
        if ($existing) {
            return [
                'rc'   => 200,
                'rm'   => 'Berhasil',
                'data' => $this->loadFullExaminationData((int)$existing->id),
            ];
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            $lockedVisit = $db->createCommand(
                "SELECT id, doctor_id, status FROM visits WHERE id = :id FOR UPDATE",
                [':id' => $id]
            )->queryOne();

            if (!in_array((string)$lockedVisit['status'], [
                Visit::STATUS_WAITING,
                Visit::STATUS_CALLED,
                Visit::STATUS_EXAMINING,
            ], true)) {
                $transaction->rollBack();
                return [
                    'rc'   => 409,
                    'rm'   => "Pemeriksaan tidak dapat dimulai pada kunjungan berstatus {$lockedVisit['status']}",
                    'data' => null,
                ];
            }

            $alreadyCreated = Examination::findOne(['visit_id' => $id]);
            if ($alreadyCreated) {
                $transaction->commit();
                return [
                    'rc'   => 200,
                    'rm'   => 'Berhasil',
                    'data' => $this->loadFullExaminationData((int)$alreadyCreated->id),
                ];
            }

            $now = date('Y-m-d H:i:s');
            $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

            $exam = new Examination();
            $exam->visit_id = $id;
            $exam->doctor_id = (int)$lockedVisit['doctor_id'];
            $exam->version = 1;
            $exam->status = Examination::STATUS_DRAFT;
            $exam->created_at = $now;
            $exam->created_by = $currentUserId;
            $exam->updated_at = $now;
            $exam->updated_by = $currentUserId;
            $exam->save(false);

            $oldVisitStatus = (string)$lockedVisit['status'];
            if ($oldVisitStatus !== Visit::STATUS_EXAMINING) {
                $db->createCommand("
                    UPDATE visits
                    SET status = :st, updated_at = :now, updated_by = :uid
                    WHERE id = :id
                ", [
                    ':st'  => Visit::STATUS_EXAMINING,
                    ':now' => $now,
                    ':uid' => $currentUserId,
                    ':id'  => $id,
                ])->execute();

                AuditLog::log(
                    'visits',
                    $id,
                    'STATUS_CHANGE',
                    ['status' => $oldVisitStatus],
                    ['status' => Visit::STATUS_EXAMINING]
                );
            }

            AuditLog::log('examinations', (int)$exam->id, 'CREATE', null, [
                'id'        => (int)$exam->id,
                'visit_id'  => $id,
                'doctor_id' => (int)$exam->doctor_id,
                'version'   => 1,
                'status'    => Examination::STATUS_DRAFT,
            ]);

            $transaction->commit();

            return [
                'rc'   => 200,
                'rm'   => 'Berhasil',
                'data' => $this->loadFullExaminationData((int)$exam->id),
            ];
        } catch (Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }

            if ($e instanceof DbException && str_contains($e->getMessage(), 'examinations_visit_id_key')) {
                $fallback = Examination::findOne(['visit_id' => $id]);
                if ($fallback) {
                    return [
                        'rc'   => 200,
                        'rm'   => 'Berhasil',
                        'data' => $this->loadFullExaminationData((int)$fallback->id),
                    ];
                }
            }

            throw $e;
        }
    }

    public function actionView(int $id): array
    {
        $denied = $this->requireRoles(['DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $exam = Examination::findOne(['id' => $id]);
        if (!$exam) {
            return [
                'rc'   => 404,
                'rm'   => 'Data pemeriksaan tidak ditemukan',
                'data' => null,
            ];
        }

        $ownDoctorId = DoctorContext::getCurrentDoctorId();
        $isOwner = ($ownDoctorId !== null && $ownDoctorId === (int)$exam->doctor_id);

        if (!$isOwner && $exam->status !== Examination::STATUS_FINAL) {
            return [
                'rc'   => 403,
                'rm'   => 'Akses ditolak: Pemeriksaan berstatus DRAFT hanya dapat diakses oleh dokter pemeriksa',
                'data' => null,
            ];
        }

        AuditLog::log('examinations', $id, 'VIEW');

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->loadFullExaminationData($id),
        ];
    }

    public function actionUpdate(int $id): array
    {
        $denied = $this->requireRoles(['DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $exam = Examination::findOne(['id' => $id]);
        if (!$exam) {
            return [
                'rc'   => 404,
                'rm'   => 'Data pemeriksaan tidak ditemukan',
                'data' => null,
            ];
        }

        $ownCheck = DoctorContext::assertOwnsVisit((int)$exam->doctor_id);
        if ($ownCheck !== null) {
            return $ownCheck;
        }

        if ($exam->status === Examination::STATUS_FINAL) {
            return [
                'rc'   => 409,
                'rm'   => 'Gunakan revisi',
                'data' => null,
            ];
        }

        $params = $this->getRequestParams();

        $diagnosesValidation = null;
        if (array_key_exists('diagnoses', $params) && $params['diagnoses'] !== null) {
            if (!is_array($params['diagnoses'])) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Format diagnoses harus berupa array',
                    'data' => ['diagnoses' => ['Format diagnoses harus berupa array']],
                ];
            }
            $diagnosesValidation = $this->validateDiagnosesInput($params['diagnoses'], false);
            if ($diagnosesValidation['error'] !== null) {
                return $diagnosesValidation['error'];
            }
        }

        $vitalSignModel = null;
        $hasVitalInput = array_key_exists('vital_sign', $params) && is_array($params['vital_sign']);
        if ($hasVitalInput) {
            $vitalCheck = $this->prepareAndValidateVitalSign((int)$exam->id, $params['vital_sign']);
            if ($vitalCheck['error'] !== null) {
                return $vitalCheck['error'];
            }
            $vitalSignModel = $vitalCheck['model'];
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            if (array_key_exists('complaint', $params)) {
                $exam->complaint = $params['complaint'] !== null ? trim((string)$params['complaint']) : null;
            }
            if (array_key_exists('anamnesis', $params)) {
                $exam->anamnesis = $params['anamnesis'] !== null ? trim((string)$params['anamnesis']) : null;
            }
            if (array_key_exists('physical_exam', $params)) {
                $exam->physical_exam = $params['physical_exam'] !== null ? trim((string)$params['physical_exam']) : null;
            }
            if (array_key_exists('plan_notes', $params)) {
                $exam->plan_notes = $params['plan_notes'] !== null ? trim((string)$params['plan_notes']) : null;
            }

            $exam->updated_at = $now;
            $exam->updated_by = $currentUserId;
            $exam->save(false);

            if ($vitalSignModel !== null) {
                $vitalSignModel->save(false);
            }

            if ($diagnosesValidation !== null) {
                $this->replaceDiagnoses((int)$exam->id, $diagnosesValidation['items'], $now);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            if ($e instanceof DbException) {
                return $this->mapClinicalDbException($e);
            }
            throw $e;
        }

        $data = $this->loadFullExaminationData((int)$exam->id);
        AuditLog::log('examinations', (int)$exam->id, 'UPDATE_DRAFT', null, $data);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionFinalize(int $id): array
    {
        $denied = $this->requireRoles(['DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $exam = Examination::findOne(['id' => $id]);
        if (!$exam) {
            return [
                'rc'   => 404,
                'rm'   => 'Data pemeriksaan tidak ditemukan',
                'data' => null,
            ];
        }

        $ownCheck = DoctorContext::assertOwnsVisit((int)$exam->doctor_id);
        if ($ownCheck !== null) {
            return $ownCheck;
        }

        if ($exam->status === Examination::STATUS_FINAL) {
            return [
                'rc'   => 409,
                'rm'   => 'Pemeriksaan sudah final',
                'data' => null,
            ];
        }

        $complaint = trim((string)$exam->complaint);
        if ($complaint === '') {
            return [
                'rc'   => 422,
                'rm'   => 'Keluhan utama (complaint) wajib diisi sebelum finalisasi',
                'data' => ['complaint' => ['Keluhan utama wajib diisi']],
            ];
        }

        $vitalSign = VitalSign::findOne(['examination_id' => $id]);
        if (!$vitalSign || !$vitalSign->hasAnyMeasurement()) {
            return [
                'rc'   => 422,
                'rm'   => 'Minimal satu tanda vital (vital_sign) wajib diisi sebelum finalisasi',
                'data' => ['vital_sign' => ['Minimal satu tanda vital wajib diisi']],
            ];
        }

        $primaryCount = (int)Diagnosis::find()
            ->where(['examination_id' => $id, 'type' => Diagnosis::TYPE_PRIMARY])
            ->count();

        if ($primaryCount !== 1) {
            return [
                'rc'   => 422,
                'rm'   => 'Pemeriksaan wajib memiliki tepat satu diagnosis utama (PRIMARY) sebelum finalisasi',
                'data' => ['diagnoses' => ['Wajib memiliki tepat satu diagnosis PRIMARY']],
            ];
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $exam->status = Examination::STATUS_FINAL;
        $exam->finalized_at = $now;
        $exam->updated_at = $now;
        $exam->updated_by = $currentUserId;
        $exam->save(false);

        $data = $this->loadFullExaminationData($id);
        AuditLog::log('examinations', $id, 'FINALIZE', ['status' => Examination::STATUS_DRAFT], [
            'status'       => Examination::STATUS_FINAL,
            'version'      => (int)$exam->version,
            'finalized_at' => $now,
        ]);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionRevise(int $id): array
    {
        $denied = $this->requireRoles(['DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $params = $this->getRequestParams();
        $reason = trim((string)($params['reason'] ?? ''));
        $versionParam = $params['version'] ?? null;

        if (mb_strlen($reason) < 10) {
            return [
                'rc'   => 422,
                'rm'   => 'Alasan revisi (reason) wajib diisi minimal 10 karakter',
                'data' => ['reason' => ['Alasan revisi wajib diisi minimal 10 karakter']],
            ];
        }

        if ($versionParam === null || $versionParam === '' || filter_var($versionParam, FILTER_VALIDATE_INT) === false) {
            return [
                'rc'   => 422,
                'rm'   => 'Parameter version wajib diisi',
                'data' => ['version' => ['Parameter version wajib diisi']],
            ];
        }

        $requestedVersion = (int)$versionParam;

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            $lockedExam = $db->createCommand(
                "SELECT * FROM examinations WHERE id = :id FOR UPDATE",
                [':id' => $id]
            )->queryOne();

            if (!$lockedExam) {
                $transaction->rollBack();
                return [
                    'rc'   => 404,
                    'rm'   => 'Data pemeriksaan tidak ditemukan',
                    'data' => null,
                ];
            }

            $ownCheck = DoctorContext::assertOwnsVisit((int)$lockedExam['doctor_id']);
            if ($ownCheck !== null) {
                $transaction->rollBack();
                return $ownCheck;
            }

            if ($lockedExam['status'] !== Examination::STATUS_FINAL) {
                $transaction->rollBack();
                return [
                    'rc'   => 409,
                    'rm'   => 'Revisi hanya dapat dilakukan pada pemeriksaan yang sudah FINAL',
                    'data' => null,
                ];
            }

            $visitStatus = (string)$db->createCommand(
                "SELECT status FROM visits WHERE id = :vid",
                [':vid' => (int)$lockedExam['visit_id']]
            )->queryScalar();

            if ($visitStatus === Visit::STATUS_CANCELLED) {
                $transaction->rollBack();
                return [
                    'rc'   => 409,
                    'rm'   => 'Kunjungan yang sudah dibatalkan tidak dapat direvisi',
                    'data' => null,
                ];
            }

            $currentVersion = (int)$lockedExam['version'];
            if ($requestedVersion !== $currentVersion) {
                $transaction->rollBack();
                return [
                    'rc'   => 409,
                    'rm'   => 'Data sudah diubah pihak lain, muat ulang',
                    'data' => null,
                ];
            }

            $oldSnapshot = $this->buildRevisionSnapshot($id, $lockedExam);

            $exam = Examination::findOne(['id' => $id]);
            if (array_key_exists('complaint', $params)) {
                $exam->complaint = $params['complaint'] !== null ? trim((string)$params['complaint']) : null;
            }
            if (array_key_exists('anamnesis', $params)) {
                $exam->anamnesis = $params['anamnesis'] !== null ? trim((string)$params['anamnesis']) : null;
            }
            if (array_key_exists('physical_exam', $params)) {
                $exam->physical_exam = $params['physical_exam'] !== null ? trim((string)$params['physical_exam']) : null;
            }
            if (array_key_exists('plan_notes', $params)) {
                $exam->plan_notes = $params['plan_notes'] !== null ? trim((string)$params['plan_notes']) : null;
            }

            if (trim((string)$exam->complaint) === '') {
                $transaction->rollBack();
                return [
                    'rc'   => 422,
                    'rm'   => 'Keluhan utama (complaint) wajib diisi pada pemeriksaan FINAL',
                    'data' => ['complaint' => ['Keluhan utama wajib diisi']],
                ];
            }

            $vitalSignModel = null;
            if (array_key_exists('vital_sign', $params) && is_array($params['vital_sign'])) {
                $vitalCheck = $this->prepareAndValidateVitalSign($id, $params['vital_sign']);
                if ($vitalCheck['error'] !== null) {
                    $transaction->rollBack();
                    return $vitalCheck['error'];
                }
                $vitalSignModel = $vitalCheck['model'];
            } else {
                $vitalSignModel = VitalSign::findOne(['examination_id' => $id]);
            }

            if (!$vitalSignModel || !$vitalSignModel->hasAnyMeasurement()) {
                $transaction->rollBack();
                return [
                    'rc'   => 422,
                    'rm'   => 'Minimal satu tanda vital (vital_sign) wajib terisi pada pemeriksaan FINAL',
                    'data' => ['vital_sign' => ['Minimal satu tanda vital wajib terisi']],
                ];
            }

            $diagnosesValidation = null;
            if (array_key_exists('diagnoses', $params) && $params['diagnoses'] !== null) {
                if (!is_array($params['diagnoses'])) {
                    $transaction->rollBack();
                    return [
                        'rc'   => 422,
                        'rm'   => 'Format diagnoses harus berupa array',
                        'data' => ['diagnoses' => ['Format diagnoses harus berupa array']],
                    ];
                }
                $diagnosesValidation = $this->validateDiagnosesInput($params['diagnoses'], true);
                if ($diagnosesValidation['error'] !== null) {
                    $transaction->rollBack();
                    return $diagnosesValidation['error'];
                }
            } else {
                $existingPrimary = (int)Diagnosis::find()
                    ->where(['examination_id' => $id, 'type' => Diagnosis::TYPE_PRIMARY])
                    ->count();
                if ($existingPrimary !== 1) {
                    $transaction->rollBack();
                    return [
                        'rc'   => 422,
                        'rm'   => 'Pemeriksaan FINAL wajib memiliki tepat satu diagnosis PRIMARY',
                        'data' => ['diagnoses' => ['Wajib memiliki tepat satu diagnosis PRIMARY']],
                    ];
                }
            }

            $now = date('Y-m-d H:i:s');
            $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

            $revision = new ExaminationRevision();
            $revision->examination_id = $id;
            $revision->version = $currentVersion;
            $revision->snapshot = $oldSnapshot;
            $revision->reason = $reason;
            $revision->changed_by = $currentUserId;
            $revision->changed_at = $now;
            $revision->save(false);

            $exam->version = $currentVersion + 1;
            $exam->updated_at = $now;
            $exam->updated_by = $currentUserId;
            $exam->save(false);

            if (array_key_exists('vital_sign', $params) && is_array($params['vital_sign'])) {
                $vitalSignModel->save(false);
            }

            if ($diagnosesValidation !== null) {
                $this->replaceDiagnoses($id, $diagnosesValidation['items'], $now);
            }

            $newSnapshot = $this->buildRevisionSnapshot($id, $exam->attributes);

            AuditLog::log(
                'examinations',
                $id,
                'REVISE',
                $oldSnapshot,
                $newSnapshot,
                $reason
            );

            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            if ($e instanceof DbException) {
                if (str_contains($e->getMessage(), 'examination_revisions_examination_id_version_key')) {
                    return [
                        'rc'   => 409,
                        'rm'   => 'Data sudah diubah pihak lain, muat ulang',
                        'data' => null,
                    ];
                }
                return $this->mapClinicalDbException($e);
            }
            throw $e;
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->loadFullExaminationData($id),
        ];
    }

    public function actionRevisions(int $id): array
    {
        $denied = $this->requireRoles(['DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $exam = Examination::findOne(['id' => $id]);
        if (!$exam) {
            return [
                'rc'   => 404,
                'rm'   => 'Data pemeriksaan tidak ditemukan',
                'data' => null,
            ];
        }

        $ownCheck = DoctorContext::assertOwnsVisit((int)$exam->doctor_id);
        if ($ownCheck !== null) {
            return $ownCheck;
        }

        $revisions = ExaminationRevision::find()
            ->where(['examination_id' => $id])
            ->orderBy(['version' => SORT_DESC])
            ->all();

        $data = [];
        foreach ($revisions as $rev) {
            $data[] = [
                'id'             => (int)$rev->id,
                'examination_id' => (int)$rev->examination_id,
                'version'        => (int)$rev->version,
                'reason'         => $rev->reason,
                'changed_by'     => $rev->changed_by !== null ? (int)$rev->changed_by : null,
                'changed_at'     => $rev->changed_at,
            ];
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionRevisionDetail(int $id, int $version): array
    {
        $denied = $this->requireRoles(['DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $exam = Examination::findOne(['id' => $id]);
        if (!$exam) {
            return [
                'rc'   => 404,
                'rm'   => 'Data pemeriksaan tidak ditemukan',
                'data' => null,
            ];
        }

        $ownCheck = DoctorContext::assertOwnsVisit((int)$exam->doctor_id);
        if ($ownCheck !== null) {
            return $ownCheck;
        }

        $rev = ExaminationRevision::findOne([
            'examination_id' => $id,
            'version'        => $version,
        ]);

        if (!$rev) {
            return [
                'rc'   => 404,
                'rm'   => 'Data revisi tidak ditemukan',
                'data' => null,
            ];
        }

        $snapshot = is_string($rev->snapshot) ? json_decode($rev->snapshot, true) : $rev->snapshot;

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => [
                'id'             => (int)$rev->id,
                'examination_id' => (int)$rev->examination_id,
                'version'        => (int)$rev->version,
                'reason'         => $rev->reason,
                'changed_by'     => $rev->changed_by !== null ? (int)$rev->changed_by : null,
                'changed_at'     => $rev->changed_at,
                'snapshot'       => $snapshot,
            ],
        ];
    }

    private function prepareAndValidateVitalSign(int $examinationId, array $input): array
    {
        $model = VitalSign::findOne(['examination_id' => $examinationId]);
        if (!$model) {
            $model = new VitalSign();
            $model->examination_id = $examinationId;
        }

        foreach (VitalSign::MEASUREMENT_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $val = $input[$field];
                $model->setAttribute($field, ($val === '' || $val === null) ? null : $val);
            }
        }

        $model->measured_at = date('Y-m-d H:i:s');

        if (!$model->validate()) {
            return [
                'model' => null,
                'error' => [
                    'rc'   => 422,
                    'rm'   => 'Validasi tanda vital gagal',
                    'data' => $model->getErrors(),
                ],
            ];
        }

        return [
            'model' => $model,
            'error' => null,
        ];
    }

    private function validateDiagnosesInput(array $diagnosesInput, bool $requirePrimary): array
    {
        $normalizedItems = [];
        $seenCodes = [];
        $primaryCount = 0;

        foreach ($diagnosesInput as $idx => $item) {
            if (!is_array($item)) {
                return [
                    'items' => [],
                    'error' => [
                        'rc'   => 422,
                        'rm'   => "Item diagnosis ke-{$idx} tidak valid",
                        'data' => ['diagnoses' => ["Item diagnosis ke-{$idx} tidak valid"]],
                    ],
                ];
            }

            $code = strtoupper(trim((string)($item['icd10_code'] ?? '')));
            $type = strtoupper(trim((string)($item['type'] ?? '')));
            $notes = isset($item['notes']) && trim((string)$item['notes']) !== '' ? trim((string)$item['notes']) : null;

            if ($code === '') {
                return [
                    'items' => [],
                    'error' => [
                        'rc'   => 422,
                        'rm'   => 'Kode ICD-10 wajib diisi pada setiap diagnosis',
                        'data' => ['icd10_code' => ['Kode ICD-10 wajib diisi']],
                    ],
                ];
            }

            if (!in_array($type, [Diagnosis::TYPE_PRIMARY, Diagnosis::TYPE_SECONDARY], true)) {
                return [
                    'items' => [],
                    'error' => [
                        'rc'   => 422,
                        'rm'   => 'Tipe diagnosis harus PRIMARY atau SECONDARY',
                        'data' => ['type' => ['Tipe diagnosis harus PRIMARY atau SECONDARY']],
                    ],
                ];
            }

            if (isset($seenCodes[$code])) {
                return [
                    'items' => [],
                    'error' => [
                        'rc'   => 422,
                        'rm'   => "Kode ICD-10 {$code} tidak boleh didaftarkan lebih dari sekali dalam satu pemeriksaan",
                        'data' => ['icd10_code' => ["Kode ICD-10 {$code} duplikat dalam satu pemeriksaan"]],
                    ],
                ];
            }
            $seenCodes[$code] = true;

            if ($type === Diagnosis::TYPE_PRIMARY) {
                $primaryCount++;
            }

            $normalizedItems[] = [
                'icd10_code' => $code,
                'type'       => $type,
                'notes'      => $notes,
            ];
        }

        if ($primaryCount > 1) {
            return [
                'items' => [],
                'error' => [
                    'rc'   => 422,
                    'rm'   => 'Maksimal hanya boleh ada satu diagnosis utama (PRIMARY)',
                    'data' => ['diagnoses' => ['Maksimal hanya boleh ada satu diagnosis PRIMARY']],
                ],
            ];
        }

        if ($requirePrimary && $primaryCount !== 1) {
            return [
                'items' => [],
                'error' => [
                    'rc'   => 422,
                    'rm'   => 'Wajib terdapat tepat satu diagnosis utama (PRIMARY)',
                    'data' => ['diagnoses' => ['Wajib terdapat tepat satu diagnosis PRIMARY']],
                ],
            ];
        }

        if (!empty($seenCodes)) {
            $codesList = array_keys($seenCodes);
            $existingCodes = Icd10::find()
                ->select('code')
                ->where(['in', 'code', $codesList])
                ->column();

            $missing = array_diff($codesList, $existingCodes);
            if (!empty($missing)) {
                $firstMissing = reset($missing);
                return [
                    'items' => [],
                    'error' => [
                        'rc'   => 422,
                        'rm'   => "Kode ICD-10 {$firstMissing} tidak ditemukan di master ICD-10",
                        'data' => ['icd10_code' => ["Kode ICD-10 {$firstMissing} tidak ditemukan di master"]],
                    ],
                ];
            }
        }

        return [
            'items' => $normalizedItems,
            'error' => null,
        ];
    }

    private function replaceDiagnoses(int $examinationId, array $items, string $now): void
    {
        Yii::$app->db->createCommand(
            "DELETE FROM diagnoses WHERE examination_id = :eid",
            [':eid' => $examinationId]
        )->execute();

        foreach ($items as $item) {
            $diag = new Diagnosis();
            $diag->examination_id = $examinationId;
            $diag->icd10_code = $item['icd10_code'];
            $diag->type = $item['type'];
            $diag->notes = $item['notes'];
            $diag->created_at = $now;
            $diag->save(false);
        }
    }

    private function buildRevisionSnapshot(int $examinationId, array $examRow): array
    {
        $vital = VitalSign::findOne(['examination_id' => $examinationId]);
        $diagnoses = (new Query())
            ->select([
                'd.id',
                'd.icd10_code',
                'icd10_name' => 'i.name',
                'd.type',
                'd.notes',
            ])
            ->from(['d' => 'diagnoses'])
            ->leftJoin(['i' => 'icd10'], 'i.code = d.icd10_code')
            ->where(['d.examination_id' => $examinationId])
            ->orderBy(['d.type' => SORT_ASC, 'd.id' => SORT_ASC])
            ->all();

        $diagList = [];
        foreach ($diagnoses as $d) {
            $diagList[] = [
                'id'         => (int)$d['id'],
                'icd10_code' => $d['icd10_code'],
                'icd10_name' => $d['icd10_name'],
                'type'       => $d['type'],
                'notes'      => $d['notes'],
            ];
        }

        return [
            'examination' => [
                'id'            => (int)$examRow['id'],
                'visit_id'      => (int)$examRow['visit_id'],
                'doctor_id'     => (int)$examRow['doctor_id'],
                'complaint'     => $examRow['complaint'],
                'anamnesis'     => $examRow['anamnesis'],
                'physical_exam' => $examRow['physical_exam'],
                'plan_notes'    => $examRow['plan_notes'],
                'version'       => (int)$examRow['version'],
                'status'        => $examRow['status'],
                'finalized_at'  => $examRow['finalized_at'],
            ],
            'vital_sign'  => $vital ? $vital->toArraySummary() : null,
            'diagnoses'   => $diagList,
        ];
    }

    private function loadFullExaminationData(int $examinationId): array
    {
        $exam = Examination::find()
            ->with(['visit.patient', 'visit.poli', 'doctor', 'vitalSign', 'diagnoses.icd10'])
            ->where(['id' => $examinationId])
            ->one();

        $vitalData = $exam->vitalSign ? $exam->vitalSign->toArraySummary() : null;

        $diagData = [];
        foreach ($exam->diagnoses as $d) {
            $diagData[] = [
                'id'         => (int)$d->id,
                'icd10_code' => $d->icd10_code,
                'icd10_name' => $d->icd10 ? $d->icd10->name : null,
                'type'       => $d->type,
                'notes'      => $d->notes,
                'created_at' => $d->created_at,
            ];
        }

        $visitData = null;
        $patientData = null;
        if ($exam->visit) {
            $v = $exam->visit;
            $visitData = [
                'id'         => (int)$v->id,
                'visit_no'   => $v->visit_no,
                'visit_date' => $v->visit_date,
                'queue_no'   => (int)$v->queue_no,
                'status'     => $v->status,
                'poli'       => $v->poli ? [
                    'id'   => (int)$v->poli->id,
                    'code' => $v->poli->code,
                    'name' => $v->poli->name,
                ] : null,
            ];

            if ($v->patient) {
                $pt = $v->patient;
                $patientData = [
                    'id'                => (int)$pt->id,
                    'medical_record_no' => $pt->medical_record_no,
                    'full_name'         => $pt->full_name,
                    'gender'            => $pt->gender,
                    'birth_date'        => $pt->birth_date,
                    'age'               => $pt->getAge(),
                ];
            }
        }

        $doctorData = $exam->doctor ? [
            'id'        => (int)$exam->doctor->id,
            'full_name' => $exam->doctor->full_name,
            'specialty' => $exam->doctor->specialty,
        ] : null;

        $procRows = (new Query())
            ->select([
                'vp.id',
                'vp.procedure_id',
                'procedure_code' => 'pr.code',
                'procedure_name' => 'pr.name',
                'vp.qty',
                'vp.unit_tariff',
                'vp.status',
                'vp.performed_at',
            ])
            ->from(['vp' => 'visit_procedures'])
            ->innerJoin(['pr' => 'procedures'], 'pr.id = vp.procedure_id')
            ->where([
                'vp.visit_id' => (int)$exam->visit_id,
                'vp.status'   => 'ACTIVE',
            ])
            ->orderBy(['vp.id' => SORT_ASC])
            ->all();

        $proceduresData = [];
        foreach ($procRows as $pr) {
            $qty = (int)$pr['qty'];
            $tariff = (float)$pr['unit_tariff'];
            $proceduresData[] = [
                'id'           => (int)$pr['id'],
                'procedure_id' => (int)$pr['procedure_id'],
                'procedure'    => [
                    'id'   => (int)$pr['procedure_id'],
                    'code' => $pr['procedure_code'],
                    'name' => $pr['procedure_name'],
                ],
                'qty'          => $qty,
                'unit_tariff'  => $tariff,
                'subtotal'     => $qty * $tariff,
                'status'       => $pr['status'],
                'performed_at' => $pr['performed_at'],
            ];
        }

        return [
            'id'            => (int)$exam->id,
            'visit_id'      => (int)$exam->visit_id,
            'doctor_id'     => (int)$exam->doctor_id,
            'complaint'     => $exam->complaint,
            'anamnesis'     => $exam->anamnesis,
            'physical_exam' => $exam->physical_exam,
            'plan_notes'    => $exam->plan_notes,
            'version'       => (int)$exam->version,
            'status'        => $exam->status,
            'finalized_at'  => $exam->finalized_at,
            'vital_sign'    => $vitalData,
            'diagnoses'     => $diagData,
            'procedures'    => $proceduresData,
            'visit'         => $visitData,
            'patient'       => $patientData,
            'doctor'        => $doctorData,
            'created_at'    => $exam->created_at,
            'created_by'    => $exam->created_by !== null ? (int)$exam->created_by : null,
            'updated_at'    => $exam->updated_at,
            'updated_by'    => $exam->updated_by !== null ? (int)$exam->updated_by : null,
        ];
    }

    private function mapClinicalDbException(DbException $e): array
    {
        $msg = $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? '';

        if (str_contains($msg, 'ux_diag_primary')) {
            return [
                'rc'   => 422,
                'rm'   => 'Maksimal hanya boleh ada satu diagnosis utama (PRIMARY)',
                'data' => ['diagnoses' => ['Maksimal hanya boleh ada satu diagnosis PRIMARY']],
            ];
        }

        if (str_contains($msg, 'ux_diag_code')) {
            return [
                'rc'   => 422,
                'rm'   => 'Kode ICD-10 tidak boleh duplikat dalam satu pemeriksaan',
                'data' => ['icd10_code' => ['Kode ICD-10 duplikat dalam satu pemeriksaan']],
            ];
        }

        if (str_contains($msg, 'diagnoses_icd10_code_fkey')) {
            return [
                'rc'   => 422,
                'rm'   => 'Kode ICD-10 tidak ditemukan di master ICD-10',
                'data' => ['icd10_code' => ['Kode ICD-10 tidak ditemukan di master']],
            ];
        }

        if ($sqlState === '23514') {
            $checkMap = [
                'vital_signs_systolic_check'    => ['systolic', 'Sistolik harus bernilai 30-300 mmHg'],
                'vital_signs_diastolic_check'   => ['diastolic', 'Diastolik harus bernilai 20-200 mmHg'],
                'vital_signs_pulse_check'       => ['pulse', 'Nadi harus bernilai 20-300 x/menit'],
                'vital_signs_resp_rate_check'   => ['resp_rate', 'Frekuensi napas harus bernilai 5-80 x/menit'],
                'vital_signs_temperature_check' => ['temperature', 'Suhu harus bernilai 25-45 derajat C'],
                'vital_signs_spo2_check'        => ['spo2', 'SpO2 harus bernilai 0-100 %'],
                'vital_signs_weight_kg_check'   => ['weight_kg', 'Berat badan harus lebih dari 0'],
                'vital_signs_height_cm_check'   => ['height_cm', 'Tinggi badan harus lebih dari 0'],
            ];

            foreach ($checkMap as $constraint => [$field, $errMsg]) {
                if (str_contains($msg, $constraint)) {
                    return [
                        'rc'   => 422,
                        'rm'   => $errMsg,
                        'data' => [$field => [$errMsg]],
                    ];
                }
            }

            return [
                'rc'   => 422,
                'rm'   => 'Nilai tanda vital di luar batas yang diizinkan',
                'data' => null,
            ];
        }

        throw $e;
    }
}
