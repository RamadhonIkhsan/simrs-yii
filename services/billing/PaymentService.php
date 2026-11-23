<?php

declare(strict_types=1);

namespace app\services\billing;

use Yii;
use app\models\AuditLog;
use app\models\Bill;
use app\models\Payment;
use yii\db\Connection;
use yii\web\HttpException;

class PaymentService
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Yii::$app->db;
    }

    /**
     * Record a CASH payment for a bill.
     *
     * @param int $billId
     * @param int $cashierId
     * @param string|null $idempotencyKey
     * @param array $body
     * @param bool $simulateLoss
     * @return array{payment: Payment, bill: Bill, is_replay: bool, simulate_loss: bool}
     * @throws HttpException
     */
    public function record(
        int $billId,
        int $cashierId,
        ?string $idempotencyKey,
        array $body,
        bool $simulateLoss = false
    ): array {
        // Validation a: Idempotency-Key
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw new HttpException(422, 'Header Idempotency-Key wajib diisi');
        }

        $idempotencyKey = trim($idempotencyKey);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $idempotencyKey)) {
            throw new HttpException(422, 'Header Idempotency-Key tidak valid (harus 8-80 karakter alfanumerik atau tanda ._:-)');
        }

        // Validation b: amount string representation and positive decimal
        $amountRaw = isset($body['amount']) ? trim((string)$body['amount']) : '';
        if ($amountRaw === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amountRaw) || bccomp($amountRaw, '0', 2) <= 0) {
            throw new HttpException(422, 'Jumlah pembayaran harus berupa angka positif dengan maksimal 2 desimal');
        }

        // Validation: method must be CASH if present
        if (isset($body['method']) && $body['method'] !== null && $body['method'] !== '') {
            $methodUpper = strtoupper(trim((string)$body['method']));
            if ($methodUpper !== Payment::METHOD_CASH) {
                throw new HttpException(422, 'Metode pembayaran selain tunai belum didukung');
            }
        }

        // Reference number
        $refNo = isset($body['reference_no']) && $body['reference_no'] !== null ? trim((string)$body['reference_no']) : null;
        if ($refNo !== null && mb_strlen($refNo) > 60) {
            throw new HttpException(422, 'Nomor referensi maksimal 60 karakter');
        }
        if ($refNo === '') {
            $refNo = null;
        }

        $amountDec = number_format((float)$amountRaw, 2, '.', '');

        try {
            return $this->executeRecordTransaction(
                $billId,
                $cashierId,
                $idempotencyKey,
                $amountDec,
                $refNo,
                $simulateLoss
            );
        } catch (\yii\db\IntegrityException $e) {
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'ux_payments_idem')) {
                return $this->resolveIdempotencyConflict($idempotencyKey, $billId, $amountDec, $refNo);
            }
            throw $e;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'ux_payments_idem')) {
                return $this->resolveIdempotencyConflict($idempotencyKey, $billId, $amountDec, $refNo);
            }
            throw $e;
        }
    }

    private function executeRecordTransaction(
        int $billId,
        int $cashierId,
        string $idempotencyKey,
        string $amountDec,
        ?string $refNo,
        bool $simulateLoss
    ): array {
        $transaction = $this->db->beginTransaction();

        try {
            // 1. Lock bill
            $billRow = $this->db->createCommand(
                "SELECT id, bill_no, status, payment_status, total_amount, paid_amount FROM bills WHERE id = :id FOR UPDATE",
                [':id' => $billId]
            )->queryOne();

            if (!$billRow) {
                throw new HttpException(404, 'Data tagihan tidak ditemukan');
            }

            // 2. Check existing payment with idempotency key
            $existingPayment = $this->db->createCommand(
                "SELECT id, bill_id, amount, reference_no, status FROM payments WHERE idempotency_key = :key FOR UPDATE",
                [':key' => $idempotencyKey]
            )->queryOne();

            if ($existingPayment) {
                $transaction->commit();
                return $this->compareFingerprint($existingPayment, $billId, $amountDec, $refNo);
            }

            // 3. Validate bill status
            $billStatus = (string)$billRow['status'];
            $paymentStatus = (string)$billRow['payment_status'];

            if ($billStatus === Bill::STATUS_DRAFT) {
                throw new HttpException(409, 'Tagihan belum diterbitkan');
            }
            if ($billStatus === Bill::STATUS_CANCELLED) {
                throw new HttpException(409, 'Tagihan sudah dibatalkan');
            }
            if ($paymentStatus === Bill::PAYMENT_STATUS_PAID) {
                throw new HttpException(409, 'Tagihan sudah lunas');
            }

            // 4. Generate payment_no from sequence
            $seq = (int)$this->db->createCommand("SELECT nextval('payment_no_seq')")->queryScalar();
            $paymentNo = 'PAY' . date('Ymd') . '-' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);

            $now = date('Y-m-d H:i:s');

            $this->db->createCommand()->insert('payments', [
                'payment_no'      => $paymentNo,
                'bill_id'         => $billId,
                'amount'          => $amountDec,
                'method'          => Payment::METHOD_CASH,
                'channel'         => null,
                'reference_no'    => $refNo,
                'idempotency_key' => $idempotencyKey,
                'status'          => Payment::STATUS_SUCCESS,
                'paid_at'         => $now,
                'cashier_id'      => $cashierId,
            ])->execute();

            $paymentId = (int)$this->db->getLastInsertID('payments_id_seq');

            // 5. Update bills atomically
            $updateResult = $this->db->createCommand("
                UPDATE bills
                SET paid_amount = paid_amount + :amt,
                    payment_status = CASE WHEN paid_amount + :amt >= total_amount THEN 'PAID' ELSE 'PARTIAL' END,
                    updated_at = NOW()
                WHERE id = :id AND paid_amount + :amt <= total_amount
                RETURNING paid_amount, total_amount, payment_status
            ", [
                ':id'  => $billId,
                ':amt' => $amountDec,
            ])->queryOne();

            if (!$updateResult) {
                $remaining = (float)$billRow['total_amount'] - (float)$billRow['paid_amount'];
                $remainingFormatted = number_format(max(0, $remaining), 0, ',', '.');
                throw new HttpException(409, "Pembayaran melebihi sisa tagihan (sisa: Rp {$remainingFormatted})");
            }

            // 6. AuditLogger
            AuditLog::log('payments', $paymentId, 'CREATE', null, [
                'payment_no'     => $paymentNo,
                'bill_id'        => $billId,
                'amount'         => $amountDec,
                'method'         => Payment::METHOD_CASH,
                'payment_status' => $updateResult['payment_status'],
            ]);

            $transaction->commit();

            $payment = Payment::findOne($paymentId);
            $bill = Bill::findOne($billId);

            return [
                'payment'       => $payment,
                'bill'          => $bill,
                'is_replay'     => false,
                'simulate_loss' => $simulateLoss,
            ];
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $e;
        }
    }

    private function compareFingerprint(
        array $existingPayment,
        int $billId,
        string $amountDec,
        ?string $refNo
    ): array {
        $existingBillId = (int)$existingPayment['bill_id'];
        $existingAmountDec = number_format((float)$existingPayment['amount'], 2, '.', '');
        $existingRefNo = $existingPayment['reference_no'] !== null ? trim((string)$existingPayment['reference_no']) : null;
        if ($existingRefNo === '') {
            $existingRefNo = null;
        }

        $isMatch = ($existingBillId === $billId)
            && (bccomp($existingAmountDec, $amountDec, 2) === 0)
            && ($existingRefNo === $refNo);

        if ($isMatch) {
            $payment = Payment::findOne((int)$existingPayment['id']);
            $bill = Bill::findOne($billId);
            return [
                'payment'       => $payment,
                'bill'          => $bill,
                'is_replay'     => true,
                'simulate_loss' => false,
            ];
        }

        throw new HttpException(409, 'Idempotency-Key sudah dipakai untuk permintaan lain');
    }

    private function resolveIdempotencyConflict(
        string $idempotencyKey,
        int $billId,
        string $amountDec,
        ?string $refNo
    ): array {
        $existingPayment = $this->db->createCommand(
            "SELECT id, bill_id, amount, reference_no, status FROM payments WHERE idempotency_key = :key",
            [':key' => $idempotencyKey]
        )->queryOne();

        if ($existingPayment) {
            return $this->compareFingerprint($existingPayment, $billId, $amountDec, $refNo);
        }

        throw new HttpException(409, 'Idempotency-Key sudah dipakai untuk permintaan lain');
    }

    /**
     * Void a previously recorded payment.
     *
     * @param int $paymentId
     * @param string $reason
     * @param int $adminId
     * @return array{payment: Payment, bill: Bill}
     * @throws HttpException
     */
    public function void(int $paymentId, string $reason, int $adminId): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new HttpException(422, 'Alasan pembatalan minimal 10 karakter');
        }

        $transaction = $this->db->beginTransaction();

        try {
            $paymentRow = $this->db->createCommand(
                "SELECT * FROM payments WHERE id = :id FOR UPDATE",
                [':id' => $paymentId]
            )->queryOne();

            if (!$paymentRow) {
                throw new HttpException(404, 'Data pembayaran tidak ditemukan');
            }

            if ($paymentRow['status'] === Payment::STATUS_VOIDED) {
                throw new HttpException(409, 'Pembayaran sudah dibatalkan sebelumnya');
            }

            $billId = (int)$paymentRow['bill_id'];
            $amountDec = number_format((float)$paymentRow['amount'], 2, '.', '');

            $billRow = $this->db->createCommand(
                "SELECT id, total_amount, paid_amount, payment_status FROM bills WHERE id = :id FOR UPDATE",
                [':id' => $billId]
            )->queryOne();

            if (!$billRow) {
                throw new HttpException(404, 'Data tagihan tidak ditemukan');
            }

            // Set payment status VOIDED
            $this->db->createCommand("
                UPDATE payments
                SET status = :status
                WHERE id = :id
            ", [
                ':status' => Payment::STATUS_VOIDED,
                ':id'     => $paymentId,
            ])->execute();

            // Atomic reduction of bill paid_amount and recalculate payment_status
            $this->db->createCommand("
                UPDATE bills
                SET paid_amount = GREATEST(0, paid_amount - :amt),
                    payment_status = CASE
                        WHEN paid_amount - :amt <= 0 THEN 'UNPAID'
                        WHEN paid_amount - :amt < total_amount THEN 'PARTIAL'
                        ELSE 'PAID'
                    END,
                    updated_at = NOW()
                WHERE id = :id
            ", [
                ':amt' => $amountDec,
                ':id'  => $billId,
            ])->execute();

            AuditLog::log('payments', $paymentId, 'VOID', [
                'status' => Payment::STATUS_SUCCESS,
            ], [
                'status' => Payment::STATUS_VOIDED,
                'reason' => $reason,
            ]);

            $transaction->commit();

            $payment = Payment::findOne($paymentId);
            $bill = Bill::findOne($billId);

            return [
                'payment' => $payment,
                'bill'    => $bill,
            ];
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $e;
        }
    }
}
