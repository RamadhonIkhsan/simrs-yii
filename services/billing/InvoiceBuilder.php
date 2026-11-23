<?php

declare(strict_types=1);

namespace app\services\billing;

use Yii;
use app\components\Terbilang;
use app\models\AuditLog;
use app\models\Bill;
use app\models\BillItem;
use app\models\Payment;
use app\models\User;
use yii\web\HttpException;

class InvoiceBuilder
{
    /**
     * Build invoice data for JSON and HTML rendering.
     *
     * @param int $billId
     * @param int|null $actorUserId
     * @param bool $isForHtmlRender If true, calculates copy marker based on previous prints
     * @return array
     * @throws HttpException
     */
    public function build(int $billId, ?int $actorUserId = null, bool $isForHtmlRender = false): array
    {
        /** @var Bill|null $bill */
        $bill = Bill::find()
            ->with(['visit', 'visit.poli', 'visit.doctor', 'patient', 'items', 'payments'])
            ->where(['id' => $billId])
            ->one();

        if (!$bill) {
            throw new HttpException(404, 'Data tagihan tidak ditemukan');
        }

        if ($bill->status === Bill::STATUS_DRAFT) {
            throw new HttpException(409, 'Tagihan belum diterbitkan');
        }

        if ($bill->status === Bill::STATUS_CANCELLED) {
            throw new HttpException(409, 'Tagihan sudah dibatalkan');
        }

        $hospital = Yii::$app->params['hospital'] ?? [
            'name'    => 'Rumah Sakit',
            'address' => '',
            'phone'   => '',
            'email'   => '',
        ];

        $visit = $bill->visit;
        $patient = $bill->patient;
        $doctor = $visit?->doctor;
        $poli = $visit?->poli;

        // Group items by category
        $groupDefinitions = [
            BillItem::TYPE_REGISTRATION => 'Pendaftaran',
            BillItem::TYPE_PROCEDURE    => 'Tindakan Medis',
            BillItem::TYPE_LAB          => 'Pemeriksaan Penunjang',
            BillItem::TYPE_DRUG         => 'Obat',
            BillItem::TYPE_ADJUSTMENT   => 'Penyesuaian',
        ];

        $groups = [];
        foreach ($groupDefinitions as $type => $label) {
            $groups[$type] = [
                'type'     => $type,
                'label'    => $label,
                'items'    => [],
                'subtotal' => 0.0,
            ];
        }

        $itemNo = 1;
        foreach ($bill->items as $item) {
            $type = $item->item_type;
            if (!isset($groups[$type])) {
                $groups[$type] = [
                    'type'     => $type,
                    'label'    => ucfirst(strtolower($type)),
                    'items'    => [],
                    'subtotal' => 0.0,
                ];
            }

            $subtotal = (float)$item->subtotal;
            $groups[$type]['items'][] = [
                'no'          => $itemNo++,
                'id'          => (int)$item->id,
                'description' => $item->description,
                'qty'         => (int)$item->qty,
                'unit_price'  => (float)$item->unit_price,
                'subtotal'    => $subtotal,
            ];
            $groups[$type]['subtotal'] += $subtotal;
        }

        // Filter out groups with 0 items
        $activeGroups = [];
        foreach ($groups as $type => $grp) {
            if (!empty($grp['items'])) {
                $activeGroups[] = $grp;
            }
        }

        $totalAmount = (float)$bill->total_amount;
        $paidAmount = (float)$bill->paid_amount;
        $outstanding = max(0.0, $totalAmount - $paidAmount);

        // Status label
        if ($bill->payment_status === Bill::PAYMENT_STATUS_PAID || $paidAmount >= $totalAmount) {
            $statusLabel = 'LUNAS';
        } elseif ($paidAmount > 0) {
            $statusLabel = 'DIBAYAR SEBAGIAN';
        } else {
            $statusLabel = 'BELUM DIBAYAR';
        }

        // Payment history (SUCCESS only)
        $paymentHistory = [];
        foreach ($bill->payments as $p) {
            if ($p->status === Payment::STATUS_SUCCESS) {
                $paymentHistory[] = [
                    'id'           => (int)$p->id,
                    'payment_no'   => $p->payment_no,
                    'paid_at'      => $p->paid_at,
                    'amount'       => (float)$p->amount,
                    'method'       => 'Tunai',
                    'reference_no' => $p->reference_no,
                ];
            }
        }

        // Copy marker (count prior PRINT logs)
        $previousPrintCount = (int)AuditLog::find()
            ->where(['entity' => 'bills', 'entity_id' => $billId, 'action' => 'PRINT'])
            ->count();

        $copyNumber = $previousPrintCount + 1;
        $isCopy = $previousPrintCount >= 1;
        $copyText = $isCopy ? "SALINAN - cetak ulang ke-{$copyNumber}" : null;

        // Current printer user
        $printedByName = 'Sistem';
        if ($actorUserId !== null) {
            $actor = User::findOne($actorUserId);
            if ($actor) {
                $printedByName = $actor->full_name ?? $actor->username;
            }
        }

        return [
            'hospital' => $hospital,
            'header'   => [
                'bill_id'     => (int)$bill->id,
                'bill_no'     => $bill->bill_no,
                'issued_at'   => $bill->issued_at,
                'visit_no'    => $visit?->visit_no,
                'visit_date'  => $visit?->visit_date,
                'poli_name'   => $poli?->name,
                'doctor_name' => $doctor?->full_name,
            ],
            'patient'  => [
                'id'                => (int)($patient?->id ?? 0),
                'medical_record_no' => $patient?->medical_record_no,
                'full_name'         => $patient?->full_name,
            ],
            'groups'   => $activeGroups,
            'summary'  => [
                'total_amount'    => $totalAmount,
                'paid_amount'     => $paidAmount,
                'outstanding'     => $outstanding,
                'payment_status'  => $bill->payment_status,
                'status_label'    => $statusLabel,
                'total_terbilang' => Terbilang::rupiah($totalAmount),
            ],
            'payments' => $paymentHistory,
            'print'    => [
                'is_copy'       => $isCopy,
                'copy_number'   => $copyNumber,
                'copy_text'     => $copyText,
                'printed_by'    => $printedByName,
                'printed_at'    => date('Y-m-d H:i:s'),
            ],
        ];
    }
}
