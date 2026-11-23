<?php

declare(strict_types=1);

namespace app\services\billing;

use Yii;
use app\components\Terbilang;
use app\models\AuditLog;
use app\models\Payment;
use app\models\User;
use yii\db\Query;
use yii\web\HttpException;

class ReceiptBuilder
{
    /**
     * Build receipt data for JSON and HTML rendering.
     *
     * @param int $paymentId
     * @param int|null $actorUserId
     * @return array
     * @throws HttpException
     */
    public function build(int $paymentId, ?int $actorUserId = null): array
    {
        /** @var Payment|null $payment */
        $payment = Payment::find()
            ->with(['bill', 'bill.patient', 'cashier'])
            ->where(['id' => $paymentId])
            ->one();

        if (!$payment) {
            throw new HttpException(404, 'Data pembayaran tidak ditemukan');
        }

        if ($payment->status === Payment::STATUS_VOIDED) {
            throw new HttpException(409, 'Pembayaran telah dibatalkan');
        }

        $hospital = Yii::$app->params['hospital'] ?? [
            'name'    => 'Rumah Sakit',
            'address' => '',
            'phone'   => '',
            'email'   => '',
        ];

        $bill = $payment->bill;
        $patient = $bill?->patient;
        $cashier = $payment->cashier;

        $amount = (float)$payment->amount;
        $totalAmount = (float)($bill?->total_amount ?? 0.0);

        // Calculate total paid up to this payment (payments with status SUCCESS and id <= this paymentId on this bill)
        $paidUpToThis = (float)(new Query())
            ->from('payments')
            ->where([
                'bill_id' => $bill?->id,
                'status'  => Payment::STATUS_SUCCESS,
            ])
            ->andWhere(['<=', 'id', $paymentId])
            ->sum('amount') ?? 0.0;

        $remaining = max(0.0, $totalAmount - $paidUpToThis);
        $statusLabel = $remaining <= 0.0001 ? 'LUNAS' : 'DIBAYAR SEBAGIAN';

        // Copy marker
        $previousPrintCount = (int)AuditLog::find()
            ->where(['entity' => 'payments', 'entity_id' => $paymentId, 'action' => 'PRINT'])
            ->count();

        $copyNumber = $previousPrintCount + 1;
        $isCopy = $previousPrintCount >= 1;
        $copyText = $isCopy ? "SALINAN - cetak ulang ke-{$copyNumber}" : null;

        $printedByName = 'Sistem';
        if ($actorUserId !== null) {
            $actor = User::findOne($actorUserId);
            if ($actor) {
                $printedByName = $actor->full_name ?? $actor->username;
            }
        }

        return [
            'hospital'     => $hospital,
            'header'       => [
                'payment_id'   => (int)$payment->id,
                'payment_no'   => $payment->payment_no,
                'paid_at'      => $payment->paid_at,
                'bill_id'      => (int)($bill?->id ?? 0),
                'bill_no'      => $bill?->bill_no,
                'cashier_name' => $cashier?->full_name ?? $cashier?->username ?? 'Kasir',
            ],
            'patient'      => [
                'id'                => (int)($patient?->id ?? 0),
                'medical_record_no' => $patient?->medical_record_no,
                'full_name'         => $patient?->full_name,
            ],
            'payment'      => [
                'amount'           => $amount,
                'amount_terbilang' => Terbilang::rupiah($amount),
                'method'           => 'Tunai',
                'reference_no'     => $payment->reference_no,
                'status'           => $payment->status,
            ],
            'bill_summary' => [
                'total_amount'      => $totalAmount,
                'paid_up_to_this'   => $paidUpToThis,
                'remaining'         => $remaining,
                'status_label'      => $statusLabel,
            ],
            'print'        => [
                'is_copy'       => $isCopy,
                'copy_number'   => $copyNumber,
                'copy_text'     => $copyText,
                'printed_by'    => $printedByName,
                'printed_at'    => date('Y-m-d H:i:s'),
            ],
        ];
    }
}
