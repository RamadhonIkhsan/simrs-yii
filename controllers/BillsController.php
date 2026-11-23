<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\Bill;
use app\services\billing\BillingService;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\Response;

class BillsController extends Controller
{
    use MasterControllerTrait;

    private BillingService $billingService;

    public function init(): void
    {
        parent::init();
        $this->billingService = new BillingService();
    }

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
                    'index'             => ['GET'],
                    'view'              => ['GET'],
                    'view-by-visit'     => ['GET'],
                    'create-for-visit'  => ['POST'],
                    'issue'             => ['POST'],
                    'cancel'            => ['POST'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function actionCreateForVisit(int $id): array
    {
        $denied = $this->requireRoles(['CASHIER']);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $isExisting = Bill::find()->where(['visit_id' => $id])->exists();

            $bill = $this->billingService->ensureDraft($id);

            $status = $isExisting ? 200 : 201;
            Yii::$app->response->statusCode = $status;

            return [
                'rc'   => $status,
                'rm'   => $isExisting ? 'Berhasil' : 'Tagihan berhasil dibuat',
                'data' => $this->formatBillDetail($bill),
            ];
        } catch (HttpException $e) {
            Yii::$app->response->statusCode = $e->statusCode;
            return [
                'rc'   => $e->statusCode,
                'rm'   => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    public function actionViewByVisit(int $id): array
    {
        $denied = $this->requireRoles(['CASHIER', 'ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $bill = Bill::find()
            ->with(['visit', 'visit.poli', 'visit.doctor', 'patient', 'items'])
            ->where(['visit_id' => $id])
            ->one();

        if (!$bill) {
            Yii::$app->response->statusCode = 404;
            return [
                'rc'   => 404,
                'rm'   => 'Data tagihan tidak ditemukan',
                'data' => null,
            ];
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->formatBillDetail($bill),
        ];
    }

    public function actionIndex(): array
    {
        $denied = $this->requireRoles(['CASHIER', 'ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $request = Yii::$app->request;
        $status = trim((string)$request->get('status', ''));
        $paymentStatus = trim((string)$request->get('payment_status', ''));
        $dateFrom = trim((string)$request->get('date_from', ''));
        $dateTo = trim((string)$request->get('date_to', ''));
        $patientId = $request->get('patient_id');
        $q = trim((string)($request->get('q') ?? $request->get('search', '')));

        $query = Bill::find()
            ->alias('b')
            ->innerJoinWith(['patient pt', 'visit v']);

        if ($status !== '') {
            $query->andWhere(['b.status' => strtoupper($status)]);
        }

        if ($paymentStatus !== '') {
            $query->andWhere(['b.payment_status' => strtoupper($paymentStatus)]);
        }

        if ($dateFrom !== '') {
            $query->andWhere(['>=', 'b.created_at', $dateFrom . ' 00:00:00']);
        }

        if ($dateTo !== '') {
            $query->andWhere(['<=', 'b.created_at', $dateTo . ' 23:59:59']);
        }

        if ($patientId !== null && $patientId !== '') {
            $query->andWhere(['b.patient_id' => (int)$patientId]);
        }

        if ($q !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'b.bill_no', $q],
                ['ilike', 'v.visit_no', $q],
                ['ilike', 'pt.full_name', $q],
                ['ilike', 'pt.medical_record_no', $q],
            ]);
        }

        $sortParam = (string)$request->get('sort', '');
        $orderBy = ['b.created_at' => SORT_DESC, 'b.id' => SORT_DESC];

        if ($sortParam !== '') {
            $direction = SORT_ASC;
            $field = $sortParam;
            if (str_starts_with($field, '-')) {
                $direction = SORT_DESC;
                $field = substr($field, 1);
            }
            if (in_array($field, ['created_at', 'bill_no', 'total_amount'], true)) {
                $orderBy = ["b.{$field}" => $direction, 'b.id' => $direction];
            }
        }

        return $this->buildPagedResponse($query, $orderBy, function (Bill $bill): array {
            $total = (float)$bill->total_amount;
            $paid = (float)$bill->paid_amount;
            $patient = $bill->patient;

            return [
                'id'             => (int)$bill->id,
                'bill_no'        => $bill->bill_no,
                'visit_no'       => $bill->visit ? $bill->visit->visit_no : '',
                'patient'        => $patient ? [
                    'id'                => (int)$patient->id,
                    'medical_record_no' => $patient->medical_record_no,
                    'full_name'         => $patient->full_name,
                ] : null,
                'status'         => $bill->status,
                'payment_status' => $bill->payment_status,
                'total_amount'   => $total,
                'paid_amount'    => $paid,
                'outstanding'    => max(0.0, $total - $paid),
                'issued_at'      => $bill->issued_at,
            ];
        });
    }

    public function actionView(int $id): array
    {
        $denied = $this->requireRoles(['CASHIER', 'ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $bill = Bill::find()
            ->with(['visit', 'visit.poli', 'visit.doctor', 'patient', 'items'])
            ->where(['id' => $id])
            ->one();

        if (!$bill) {
            Yii::$app->response->statusCode = 404;
            return [
                'rc'   => 404,
                'rm'   => 'Data tagihan tidak ditemukan',
                'data' => null,
            ];
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->formatBillDetail($bill),
        ];
    }

    public function actionIssue(int $id): array
    {
        $denied = $this->requireRoles(['CASHIER']);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $bill = $this->billingService->issue($id);

            return [
                'rc'   => 200,
                'rm'   => 'Tagihan berhasil diterbitkan',
                'data' => $this->formatBillDetail($bill),
            ];
        } catch (HttpException $e) {
            Yii::$app->response->statusCode = $e->statusCode;
            return [
                'rc'   => $e->statusCode,
                'rm'   => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    public function actionCancel(int $id): array
    {
        $denied = $this->requireRoles(['ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $params = $this->getRequestParams();
        $reason = trim((string)($params['reason'] ?? ''));

        if (mb_strlen($reason) < 10) {
            Yii::$app->response->statusCode = 422;
            return [
                'rc'   => 422,
                'rm'   => 'Alasan pembatalan minimal 10 karakter',
                'data' => null,
            ];
        }

        try {
            $bill = $this->billingService->cancelDraft($id, $reason);

            return [
                'rc'   => 200,
                'rm'   => 'Tagihan berhasil dibatalkan',
                'data' => $this->formatBillDetail($bill),
            ];
        } catch (HttpException $e) {
            Yii::$app->response->statusCode = $e->statusCode;
            return [
                'rc'   => $e->statusCode,
                'rm'   => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    private function formatBillDetail(Bill $bill): array
    {
        $bill->refresh();
        $visit = $bill->visit;
        $patient = $bill->patient;

        $items = [];
        $subtotals = [
            'REGISTRATION' => 0.0,
            'PROCEDURE'    => 0.0,
            'LAB'          => 0.0,
            'DRUG'         => 0.0,
            'ADJUSTMENT'   => 0.0,
        ];

        foreach ($bill->items as $item) {
            $subtotal = (float)$item->subtotal;
            $items[] = [
                'id'          => (int)$item->id,
                'item_type'   => $item->item_type,
                'ref_table'   => $item->ref_table,
                'ref_id'      => $item->ref_id !== null ? (int)$item->ref_id : null,
                'description' => $item->description,
                'qty'         => (int)$item->qty,
                'unit_price'  => (float)$item->unit_price,
                'subtotal'    => $subtotal,
            ];

            if (isset($subtotals[$item->item_type])) {
                $subtotals[$item->item_type] += $subtotal;
            } else {
                $subtotals[$item->item_type] = $subtotal;
            }
        }

        $totalAmount = (float)$bill->total_amount;
        $paidAmount = (float)$bill->paid_amount;
        $outstanding = max(0.0, $totalAmount - $paidAmount);

        return [
            'id'             => (int)$bill->id,
            'bill_no'        => $bill->bill_no,
            'version'        => (int)$bill->version,
            'status'         => $bill->status,
            'payment_status' => $bill->payment_status,
            'total_amount'   => $totalAmount,
            'paid_amount'    => $paidAmount,
            'outstanding'    => $outstanding,
            'created_at'     => $bill->created_at,
            'issued_at'      => $bill->issued_at,
            'visit'          => $visit ? [
                'id'         => (int)$visit->id,
                'visit_no'   => $visit->visit_no,
                'visit_date' => (string)$visit->visit_date,
                'poli'       => $visit->poli ? [
                    'id'   => (int)$visit->poli->id,
                    'code' => $visit->poli->code,
                    'name' => $visit->poli->name,
                ] : null,
                'doctor'     => $visit->doctor ? [
                    'id'        => (int)$visit->doctor->id,
                    'full_name' => $visit->doctor->full_name,
                ] : null,
            ] : null,
            'patient'        => $patient ? [
                'id'                => (int)$patient->id,
                'medical_record_no' => $patient->medical_record_no,
                'full_name'         => $patient->full_name,
            ] : null,
            'items'          => $items,
            'subtotals'      => $subtotals,
            'payments'       => [],
        ];
    }
}
