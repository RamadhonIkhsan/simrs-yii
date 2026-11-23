<?php

declare(strict_types=1);

namespace app\services\billing;

use Yii;
use app\models\AuditLog;
use app\models\Bill;
use app\models\BillItem;
use app\models\Visit;
use app\services\billing\sources\BillSourceInterface;
use app\services\billing\sources\DrugSource;
use app\services\billing\sources\LabSource;
use app\services\billing\sources\ProcedureSource;
use app\services\billing\sources\RegistrationSource;
use yii\db\Connection;
use yii\db\Query;
use yii\web\HttpException;

class BillingService
{
    /** @var BillSourceInterface[] */
    private array $sources;
    private Connection $db;

    public function __construct(?array $sources = null, ?Connection $db = null)
    {
        $this->db = $db ?? Yii::$app->db;
        $this->sources = $sources ?? [
            new RegistrationSource(),
            new ProcedureSource(),
            new LabSource(),
            new DrugSource(),
        ];
    }

    public function ensureDraft(int $visitId): Bill
    {
        $transaction = $this->db->beginTransaction();

        try {
            $visit = $this->db->createCommand(
                "SELECT id, patient_id, status FROM visits WHERE id = :id FOR UPDATE",
                [':id' => $visitId]
            )->queryOne();

            if (!$visit) {
                throw new HttpException(404, 'Data kunjungan tidak ditemukan');
            }

            if ($visit['status'] !== Visit::STATUS_DONE) {
                throw new HttpException(409, 'Tagihan hanya dapat dibuat untuk kunjungan yang sudah selesai');
            }

            $bill = Bill::findBySql("SELECT * FROM bills WHERE visit_id = :visit_id FOR UPDATE", [':visit_id' => $visitId])->one();

            $currentUserId = Yii::$app->has('user') && Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

            if (!$bill) {
                try {
                    $seq = (int)$this->db->createCommand("SELECT nextval('bill_no_seq')")->queryScalar();
                    $billNo = 'INV' . date('Ymd') . '-' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);

                    $now = date('Y-m-d H:i:s');
                    $this->db->createCommand()->insert('bills', [
                        'bill_no' => $billNo,
                        'visit_id' => $visitId,
                        'patient_id' => (int)$visit['patient_id'],
                        'version' => 1,
                        'status' => Bill::STATUS_DRAFT,
                        'payment_status' => Bill::PAYMENT_STATUS_UNPAID,
                        'total_amount' => 0,
                        'paid_amount' => 0,
                        'created_at' => $now,
                        'created_by' => $currentUserId,
                        'updated_at' => $now,
                        'updated_by' => $currentUserId,
                    ])->execute();

                    $bill = Bill::findBySql("SELECT * FROM bills WHERE visit_id = :visit_id FOR UPDATE", [':visit_id' => $visitId])->one();
                } catch (\Throwable $e) {
                    $bill = Bill::findBySql("SELECT * FROM bills WHERE visit_id = :visit_id FOR UPDATE", [':visit_id' => $visitId])->one();
                    if (!$bill) {
                        throw $e;
                    }
                }
            }

            if ($bill->status === Bill::STATUS_ISSUED) {
                throw new HttpException(409, 'Tagihan sudah diterbitkan; gunakan penyesuaian');
            }

            $bill = $this->syncDraft((int)$bill->id);

            $transaction->commit();
            return $bill;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public function syncDraft(int $billId): Bill
    {
        $transaction = $this->db->beginTransaction();

        try {
            /** @var Bill|null $bill */
            $bill = Bill::findBySql("SELECT * FROM bills WHERE id = :id FOR UPDATE", [':id' => $billId])->one();
            if (!$bill) {
                throw new HttpException(404, 'Data tagihan tidak ditemukan');
            }

            if ($bill->status !== Bill::STATUS_DRAFT) {
                throw new HttpException(409, 'Tagihan sudah diterbitkan; gunakan penyesuaian');
            }

            $oldTotal = (float)$bill->total_amount;
            $oldItemCount = (int)(new Query())
                ->from('bill_items')
                ->where(['bill_id' => $billId])
                ->count('*', $this->db);

            $visitId = (int)$bill->visit_id;
            $allCollectedItems = [];
            $collectedKeys = [];

            foreach ($this->sources as $source) {
                $collected = $source->collect($visitId);
                foreach ($collected as $item) {
                    $allCollectedItems[] = $item;
                    $key = ($item['ref_table'] ?? '') . ':' . ($item['ref_id'] ?? '');
                    $collectedKeys[$key] = true;
                }
            }

            $upsertSql = "
                INSERT INTO bill_items (bill_id, item_type, ref_table, ref_id, description, qty, unit_price, created_at)
                VALUES (:bill_id, :item_type, :ref_table, :ref_id, :description, :qty, :unit_price, NOW())
                ON CONFLICT (bill_id, ref_table, ref_id) WHERE ref_id IS NOT NULL AND item_type <> 'ADJUSTMENT'
                DO UPDATE SET
                    item_type = EXCLUDED.item_type,
                    description = EXCLUDED.description,
                    qty = EXCLUDED.qty,
                    unit_price = EXCLUDED.unit_price
            ";

            foreach ($allCollectedItems as $item) {
                $this->db->createCommand($upsertSql, [
                    ':bill_id'     => $billId,
                    ':item_type'   => $item['item_type'],
                    ':ref_table'   => $item['ref_table'],
                    ':ref_id'      => $item['ref_id'],
                    ':description' => $item['description'],
                    ':qty'         => (int)$item['qty'],
                    ':unit_price'  => (float)$item['unit_price'],
                ])->execute();
            }

            $existingRows = (new Query())
                ->select(['id', 'ref_table', 'ref_id'])
                ->from('bill_items')
                ->where(['bill_id' => $billId])
                ->andWhere(['<>', 'item_type', BillItem::TYPE_ADJUSTMENT])
                ->all($this->db);

            $deleteIds = [];
            foreach ($existingRows as $existing) {
                $key = ($existing['ref_table'] ?? '') . ':' . ($existing['ref_id'] ?? '');
                if (!isset($collectedKeys[$key])) {
                    $deleteIds[] = (int)$existing['id'];
                }
            }

            if (!empty($deleteIds)) {
                $this->db->createCommand()
                    ->delete('bill_items', ['id' => $deleteIds])
                    ->execute();
            }

            $currentUserId = Yii::$app->has('user') && Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

            $this->db->createCommand("
                UPDATE bills
                SET total_amount = (SELECT COALESCE(SUM(subtotal), 0) FROM bill_items WHERE bill_id = :bill_id),
                    updated_at = NOW(),
                    updated_by = :updated_by
                WHERE id = :bill_id
            ", [
                ':bill_id'    => $billId,
                ':updated_by' => $currentUserId,
            ])->execute();

            $bill->refresh();
            $newTotal = (float)$bill->total_amount;
            $newItemCount = (int)(new Query())
                ->from('bill_items')
                ->where(['bill_id' => $billId])
                ->count('*', $this->db);

            if (abs($oldTotal - $newTotal) > 0.0001 || $oldItemCount !== $newItemCount) {
                AuditLog::log('bills', $billId, 'SYNC', [
                    'total_amount' => $oldTotal,
                    'item_count'   => $oldItemCount,
                ], [
                    'total_amount' => $newTotal,
                    'item_count'   => $newItemCount,
                ]);
            }

            $transaction->commit();
            return $bill;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public function issue(int $billId): Bill
    {
        $transaction = $this->db->beginTransaction();

        try {
            /** @var Bill|null $bill */
            $bill = Bill::findBySql("SELECT * FROM bills WHERE id = :id FOR UPDATE", [':id' => $billId])->one();
            if (!$bill) {
                throw new HttpException(404, 'Data tagihan tidak ditemukan');
            }

            if ($bill->status === Bill::STATUS_ISSUED) {
                throw new HttpException(409, 'Tagihan sudah diterbitkan');
            }

            if ($bill->status === Bill::STATUS_CANCELLED) {
                throw new HttpException(409, 'Tagihan sudah dibatalkan');
            }

            if ($bill->status !== Bill::STATUS_DRAFT) {
                throw new HttpException(409, 'Hanya tagihan DRAFT yang dapat diterbitkan');
            }

            // Sync latest source data before issuing
            $bill = $this->syncDraft($billId);

            $pendingRxCount = (new Query())
                ->from('prescriptions')
                ->where(['visit_id' => $bill->visit_id])
                ->andWhere(['in', 'status', ['PENDING', 'PROCESSING']])
                ->count('*', $this->db);

            if ((int)$pendingRxCount > 0) {
                throw new HttpException(409, 'Masih ada resep yang belum diserahkan');
            }

            $totalAmount = (float)$bill->total_amount;
            $newPaymentStatus = $bill->payment_status;
            if ($totalAmount <= 0) {
                $newPaymentStatus = Bill::PAYMENT_STATUS_PAID;
            }

            $now = date('Y-m-d H:i:s');
            $currentUserId = Yii::$app->has('user') && Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

            $oldValues = [
                'status'         => $bill->status,
                'payment_status' => $bill->payment_status,
                'issued_at'      => $bill->issued_at,
            ];

            $this->db->createCommand("
                UPDATE bills
                SET status = :status,
                    payment_status = :payment_status,
                    issued_at = :issued_at,
                    updated_at = :updated_at,
                    updated_by = :updated_by
                WHERE id = :id
            ", [
                ':id'             => $billId,
                ':status'         => Bill::STATUS_ISSUED,
                ':payment_status' => $newPaymentStatus,
                ':issued_at'      => $now,
                ':updated_at'     => $now,
                ':updated_by'     => $currentUserId,
            ])->execute();

            $bill->refresh();

            AuditLog::log('bills', $billId, 'ISSUE', $oldValues, [
                'status'         => Bill::STATUS_ISSUED,
                'payment_status' => $newPaymentStatus,
                'issued_at'      => $now,
            ]);

            $transaction->commit();
            return $bill;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public function cancelDraft(int $billId, string $reason): Bill
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new HttpException(422, 'Alasan pembatalan minimal 10 karakter');
        }

        $transaction = $this->db->beginTransaction();

        try {
            /** @var Bill|null $bill */
            $bill = Bill::findBySql("SELECT * FROM bills WHERE id = :id FOR UPDATE", [':id' => $billId])->one();
            if (!$bill) {
                throw new HttpException(404, 'Data tagihan tidak ditemukan');
            }

            if ($bill->status !== Bill::STATUS_DRAFT) {
                throw new HttpException(409, 'Hanya tagihan DRAFT yang dapat dibatalkan');
            }

            if ((float)$bill->paid_amount > 0) {
                throw new HttpException(409, 'Tagihan yang sudah memiliki pembayaran tidak dapat dibatalkan');
            }

            $now = date('Y-m-d H:i:s');
            $currentUserId = Yii::$app->has('user') && Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

            $oldValues = [
                'status' => $bill->status,
            ];

            $this->db->createCommand("
                UPDATE bills
                SET status = :status,
                    updated_at = :updated_at,
                    updated_by = :updated_by
                WHERE id = :id
            ", [
                ':id'         => $billId,
                ':status'     => Bill::STATUS_CANCELLED,
                ':updated_at' => $now,
                ':updated_by' => $currentUserId,
            ])->execute();

            $bill->refresh();

            AuditLog::log('bills', $billId, 'CANCEL', $oldValues, [
                'status' => Bill::STATUS_CANCELLED,
                'reason' => $reason,
            ]);

            $transaction->commit();
            return $bill;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
