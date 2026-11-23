<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\DoctorContext;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\AuditLog;
use app\models\Procedure;
use app\models\Visit;
use app\models\VisitProcedure;
use Throwable;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class VisitProceduresController extends Controller
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
                    'create' => ['POST'],
                    'void'   => ['POST'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function actionIndex(int $id): array
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

        $includeVoidedParam = Yii::$app->request->get('include_voided');
        $includeVoided = $includeVoidedParam !== null && $includeVoidedParam !== ''
            && filter_var($includeVoidedParam, FILTER_VALIDATE_BOOLEAN);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->loadVisitProceduresPayload($id, $includeVoided),
        ];
    }

    public function actionCreate(int $id): array
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

        if (!in_array((string)$visit->status, [Visit::STATUS_EXAMINING, Visit::STATUS_DONE], true)) {
            return [
                'rc'   => 409,
                'rm'   => 'Tindakan hanya dapat dicatat pada kunjungan yang sedang atau sudah selesai diperiksa',
                'data' => null,
            ];
        }

        $params = $this->getRequestParams();
        $items = $params['items'] ?? null;
        $reason = trim((string)($params['reason'] ?? ''));

        if ($visit->status === Visit::STATUS_DONE && mb_strlen($reason) < 10) {
            return [
                'rc'   => 422,
                'rm'   => 'Alasan penambahan tindakan (reason) wajib diisi minimal 10 karakter pada kunjungan yang sudah selesai (DONE)',
                'data' => ['reason' => ['Alasan wajib diisi minimal 10 karakter pada kunjungan DONE']],
            ];
        }

        if (!is_array($items) || count($items) < 1 || count($items) > 20) {
            return [
                'rc'   => 422,
                'rm'   => 'Daftar tindakan (items) wajib berisi antara 1 sampai 20 item',
                'data' => ['items' => ['Daftar tindakan wajib berisi 1 sampai 20 item']],
            ];
        }

        $validatedItems = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                return [
                    'rc'   => 422,
                    'rm'   => "Format item ke-{$idx} tidak valid",
                    'data' => ['items' => ["Format item ke-{$idx} tidak valid"]],
                ];
            }

            $procIdRaw = $item['procedure_id'] ?? null;
            if ($procIdRaw === null || $procIdRaw === '' || filter_var($procIdRaw, FILTER_VALIDATE_INT) === false) {
                return [
                    'rc'   => 422,
                    'rm'   => "procedure_id pada item ke-{$idx} wajib berupa angka valid",
                    'data' => ['procedure_id' => ["procedure_id pada item ke-{$idx} tidak valid"]],
                ];
            }

            $qtyRaw = $item['qty'] ?? 1;
            if (filter_var($qtyRaw, FILTER_VALIDATE_INT) === false) {
                return [
                    'rc'   => 422,
                    'rm'   => "qty pada item ke-{$idx} harus berupa bilangan bulat",
                    'data' => ['qty' => ["qty pada item ke-{$idx} harus berupa bilangan bulat"]],
                ];
            }

            $qty = (int)$qtyRaw;
            if ($qty < 1 || $qty > 99) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Jumlah tindakan (qty) harus bernilai antara 1 sampai 99',
                    'data' => ['qty' => ['qty harus bernilai antara 1 sampai 99']],
                ];
            }

            $procId = (int)$procIdRaw;
            $procedure = Procedure::findOne([
                'id'         => $procId,
                'is_active'  => true,
                'deleted_at' => null,
            ]);

            if (!$procedure) {
                return [
                    'rc'   => 422,
                    'rm'   => "Tindakan medis dengan ID {$procId} tidak ditemukan atau tidak aktif",
                    'data' => ['procedure_id' => ["Tindakan medis ID {$procId} tidak ditemukan atau tidak aktif"]],
                ];
            }

            $validatedItems[] = [
                'procedure'   => $procedure,
                'qty'         => $qty,
                'unit_tariff' => (float)$procedure->tariff,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        $createdRecords = [];
        $auditItems = [];

        try {
            foreach ($validatedItems as $vItem) {
                /** @var Procedure $proc */
                $proc = $vItem['procedure'];

                $vp = new VisitProcedure();
                $vp->visit_id = $id;
                $vp->procedure_id = (int)$proc->id;
                $vp->doctor_id = (int)$visit->doctor_id;
                $vp->qty = $vItem['qty'];
                $vp->unit_tariff = $vItem['unit_tariff'];
                $vp->status = VisitProcedure::STATUS_ACTIVE;
                $vp->performed_at = $now;
                $vp->created_at = $now;
                $vp->created_by = $currentUserId;
                $vp->save(false);

                $vp->populateRelation('procedure', $proc);
                $formatted = $this->dataVisitProcedure($vp);
                $createdRecords[] = $formatted;
                $auditItems[] = [
                    'visit_procedure_id' => (int)$vp->id,
                    'procedure_id'       => (int)$proc->id,
                    'procedure_code'     => $proc->code,
                    'procedure_name'     => $proc->name,
                    'qty'                => $vItem['qty'],
                    'unit_tariff'        => $vItem['unit_tariff'],
                    'subtotal'           => $formatted['subtotal'],
                ];
            }

            AuditLog::log(
                'visits',
                $id,
                'ADD_PROCEDURE',
                null,
                ['items' => $auditItems],
                $reason !== '' ? $reason : null
            );

            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $e;
        }

        $fullPayload = $this->loadVisitProceduresPayload($id, false);
        $fullPayload['added_items'] = $createdRecords;

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $fullPayload,
        ];
    }

    public function actionVoid(int $id, int $vpId): array
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

        if (!in_array((string)$visit->status, [Visit::STATUS_EXAMINING, Visit::STATUS_DONE], true)) {
            return [
                'rc'   => 409,
                'rm'   => 'Tindakan tidak dapat dibatalkan pada status kunjungan saat ini',
                'data' => null,
            ];
        }

        $vp = VisitProcedure::find()
            ->with(['procedure'])
            ->where(['id' => $vpId, 'visit_id' => $id])
            ->one();

        if (!$vp) {
            return [
                'rc'   => 404,
                'rm'   => 'Data tindakan kunjungan tidak ditemukan',
                'data' => null,
            ];
        }

        if ($vp->status === VisitProcedure::STATUS_VOIDED) {
            return [
                'rc'   => 409,
                'rm'   => 'Tindakan medis ini sudah dibatalkan (VOIDED)',
                'data' => null,
            ];
        }

        $params = $this->getRequestParams();
        $reason = trim((string)($params['reason'] ?? ''));

        if (mb_strlen($reason) < 10) {
            return [
                'rc'   => 422,
                'rm'   => 'Alasan pembatalan tindakan (reason) wajib diisi minimal 10 karakter',
                'data' => ['reason' => ['Alasan pembatalan wajib diisi minimal 10 karakter']],
            ];
        }

        $oldValues = $this->dataVisitProcedure($vp);

        $vp->status = VisitProcedure::STATUS_VOIDED;
        $vp->void_reason = $reason;
        $vp->save(false);

        $newValues = $this->dataVisitProcedure($vp);

        AuditLog::log(
            'visit_procedures',
            $vpId,
            'VOID_PROCEDURE',
            $oldValues,
            $newValues,
            $reason
        );

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $newValues,
        ];
    }

    private function loadVisitProceduresPayload(int $visitId, bool $includeVoided): array
    {
        $allRows = VisitProcedure::find()
            ->with(['procedure'])
            ->where(['visit_id' => $visitId])
            ->orderBy(['performed_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $items = [];
        $activeCount = 0;
        $totalQty = 0;
        $totalTariff = 0.0;

        foreach ($allRows as $vp) {
            $formatted = $this->dataVisitProcedure($vp);
            if ($vp->status === VisitProcedure::STATUS_ACTIVE) {
                $activeCount++;
                $totalQty += (int)$vp->qty;
                $totalTariff += $formatted['subtotal'];
            }

            if ($includeVoided || $vp->status === VisitProcedure::STATUS_ACTIVE) {
                $items[] = $formatted;
            }
        }

        return [
            'visit_id' => $visitId,
            'items'    => $items,
            'summary'  => [
                'active_count' => $activeCount,
                'total_qty'    => $totalQty,
                'total_tariff' => $totalTariff,
                'total'        => $totalTariff,
            ],
        ];
    }

    private function dataVisitProcedure(VisitProcedure $vp): array
    {
        $qty = (int)$vp->qty;
        $unitTariff = (float)$vp->unit_tariff;

        return [
            'id'           => (int)$vp->id,
            'visit_id'     => (int)$vp->visit_id,
            'procedure_id' => (int)$vp->procedure_id,
            'doctor_id'    => (int)$vp->doctor_id,
            'procedure'    => $vp->procedure ? [
                'id'   => (int)$vp->procedure->id,
                'code' => $vp->procedure->code,
                'name' => $vp->procedure->name,
            ] : null,
            'qty'          => $qty,
            'unit_tariff'  => $unitTariff,
            'subtotal'     => $qty * $unitTariff,
            'status'       => $vp->status,
            'void_reason'  => $vp->void_reason,
            'performed_at' => $vp->performed_at,
            'created_at'   => $vp->created_at,
            'created_by'   => $vp->created_by !== null ? (int)$vp->created_by : null,
        ];
    }
}
