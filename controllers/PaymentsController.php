<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\Bill;
use app\models\Payment;
use app\services\billing\PaymentService;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\Response;

class PaymentsController extends Controller
{
    use MasterControllerTrait;

    private PaymentService $paymentService;

    public function init(): void
    {
        parent::init();
        $this->paymentService = new PaymentService();
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
                    'create-for-bill'  => ['POST'],
                    'index-by-bill'    => ['GET'],
                    'index'            => ['GET'],
                    'view'             => ['GET'],
                    'index-by-patient' => ['GET'],
                    'void'             => ['POST'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function actionCreateForBill(int $id): array
    {
        $denied = $this->requireRoles(['CASHIER']);
        if ($denied !== null) {
            return $denied;
        }

        $request = Yii::$app->request;
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        $simulateLossHeader = $request->headers->get('X-Simulate-Response-Loss');
        $simulateLoss = defined('YII_ENV_DEV') && YII_ENV_DEV && $simulateLossHeader === '1';

        $cashierId = (int)Yii::$app->user->id;
        $body = $this->getRequestParams();

        try {
            $result = $this->paymentService->record($id, $cashierId, $idempotencyKey, $body, $simulateLoss);

            if (!empty($result['simulate_loss'])) {
                Yii::$app->response->statusCode = 504;
                return [
                    'rc'   => 504,
                    'rm'   => 'Simulasi gangguan jaringan setelah transaksi tersimpan',
                    'data' => null,
                ];
            }

            if (!empty($result['is_replay'])) {
                Yii::$app->response->headers->set('Idempotent-Replay', 'true');
                Yii::$app->response->statusCode = 200;
                return [
                    'rc'   => 200,
                    'rm'   => 'Pembayaran berhasil (Replay)',
                    'data' => $this->formatPayment($result['payment'], $result['bill']),
                ];
            }

            Yii::$app->response->statusCode = 201;
            return [
                'rc'   => 201,
                'rm'   => 'Pembayaran berhasil dicatat',
                'data' => $this->formatPayment($result['payment'], $result['bill']),
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

    public function actionIndexByBill(int $id): array
    {
        $denied = $this->requireRoles(['CASHIER', 'ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $bill = Bill::findOne($id);
        if (!$bill) {
            Yii::$app->response->statusCode = 404;
            return [
                'rc'   => 404,
                'rm'   => 'Data tagihan tidak ditemukan',
                'data' => null,
            ];
        }

        $payments = Payment::find()
            ->with(['cashier'])
            ->where(['bill_id' => $id])
            ->orderBy(['paid_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $data = [];
        foreach ($payments as $p) {
            $data[] = $this->formatPayment($p, $bill);
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
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
        $billId = $request->get('bill_id');
        $cashierId = $request->get('cashier_id');
        $dateFrom = trim((string)$request->get('date_from', ''));
        $dateTo = trim((string)$request->get('date_to', ''));
        $q = trim((string)($request->get('q') ?? $request->get('search', '')));

        $query = Payment::find()
            ->alias('p')
            ->innerJoinWith(['bill b', 'bill.patient pt', 'cashier c']);

        if ($status !== '') {
            $query->andWhere(['p.status' => strtoupper($status)]);
        }

        if ($billId !== null && $billId !== '') {
            $query->andWhere(['p.bill_id' => (int)$billId]);
        }

        if ($cashierId !== null && $cashierId !== '') {
            $query->andWhere(['p.cashier_id' => (int)$cashierId]);
        }

        if ($dateFrom !== '') {
            $query->andWhere(['>=', 'p.paid_at', $dateFrom . ' 00:00:00']);
        }

        if ($dateTo !== '') {
            $query->andWhere(['<=', 'p.paid_at', $dateTo . ' 23:59:59']);
        }

        if ($q !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'p.payment_no', $q],
                ['ilike', 'b.bill_no', $q],
                ['ilike', 'pt.full_name', $q],
                ['ilike', 'pt.medical_record_no', $q],
            ]);
        }

        // Summary total_success_amount on filtered query
        $summaryQuery = (clone $query)->andWhere(['p.status' => Payment::STATUS_SUCCESS]);
        $totalSuccessAmount = (float)$summaryQuery->sum('p.amount');

        $sortParam = (string)$request->get('sort', '');
        $orderBy = ['p.paid_at' => SORT_DESC, 'p.id' => SORT_DESC];

        if ($sortParam !== '') {
            $direction = SORT_ASC;
            $field = $sortParam;
            if (str_starts_with($field, '-')) {
                $direction = SORT_DESC;
                $field = substr($field, 1);
            }
            if (in_array($field, ['paid_at', 'amount'], true)) {
                $orderBy = ["p.{$field}" => $direction, 'p.id' => $direction];
            }
        }

        $paged = $this->buildPagedResponse($query, $orderBy, function (Payment $p): array {
            return $this->formatPayment($p);
        });

        $paged['summary'] = [
            'total_success_amount' => $totalSuccessAmount,
        ];

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $paged,
        ];
    }

    public function actionView(int $id): array
    {
        $denied = $this->requireRoles(['CASHIER', 'ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $payment = Payment::find()
            ->with(['bill', 'bill.patient', 'cashier'])
            ->where(['id' => $id])
            ->one();

        if (!$payment) {
            Yii::$app->response->statusCode = 404;
            return [
                'rc'   => 404,
                'rm'   => 'Data pembayaran tidak ditemukan',
                'data' => null,
            ];
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->formatPayment($payment),
        ];
    }

    public function actionIndexByPatient(int $id): array
    {
        $denied = $this->requireRoles(['CASHIER', 'ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $query = Payment::find()
            ->alias('p')
            ->innerJoinWith(['bill b', 'bill.patient pt', 'cashier c'])
            ->where(['b.patient_id' => $id]);

        $orderBy = ['p.paid_at' => SORT_DESC, 'p.id' => SORT_DESC];

        $paged = $this->buildPagedResponse($query, $orderBy, function (Payment $p): array {
            return $this->formatPayment($p);
        });

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $paged,
        ];
    }

    public function actionVoid(int $id): array
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

        $adminId = (int)Yii::$app->user->id;

        try {
            $result = $this->paymentService->void($id, $reason, $adminId);

            return [
                'rc'   => 200,
                'rm'   => 'Pembayaran berhasil dibatalkan (VOID)',
                'data' => $this->formatPayment($result['payment'], $result['bill']),
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

    private function formatPayment(Payment $payment, ?Bill $bill = null): array
    {
        $bill = $bill ?? $payment->bill;
        $patient = $bill ? $bill->patient : null;
        $cashier = $payment->cashier;

        $totalAmount = $bill ? (float)$bill->total_amount : 0.0;
        $paidAmount = $bill ? (float)$bill->paid_amount : 0.0;
        $outstanding = max(0.0, $totalAmount - $paidAmount);

        return [
            'id'              => (int)$payment->id,
            'payment_no'      => $payment->payment_no,
            'bill_id'         => (int)$payment->bill_id,
            'amount'          => (float)$payment->amount,
            'method'          => $payment->method,
            'channel'         => $payment->channel,
            'reference_no'    => $payment->reference_no,
            'idempotency_key' => $payment->idempotency_key,
            'status'          => $payment->status,
            'paid_at'         => $payment->paid_at,
            'cashier'         => $cashier ? [
                'id'        => (int)$cashier->id,
                'username'  => $cashier->username,
                'full_name' => $cashier->full_name,
            ] : null,
            'bill'            => $bill ? [
                'id'             => (int)$bill->id,
                'bill_no'        => $bill->bill_no,
                'total_amount'   => $totalAmount,
                'paid_amount'    => $paidAmount,
                'outstanding'    => $outstanding,
                'payment_status' => $bill->payment_status,
            ] : null,
            'patient'         => $patient ? [
                'id'                => (int)$patient->id,
                'medical_record_no' => $patient->medical_record_no,
                'full_name'         => $patient->full_name,
            ] : null,
        ];
    }
}
