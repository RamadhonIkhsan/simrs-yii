<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array $data */

use yii\helpers\Html;

$hospital     = $data['hospital'] ?? [];
$header       = $data['header'] ?? [];
$patient      = $data['patient'] ?? [];
$payment      = $data['payment'] ?? [];
$billSummary  = $data['bill_summary'] ?? [];
$print        = $data['print'] ?? [];
?>
<div class="receipt-sheet">
    <!-- Kop Singkat -->
    <div class="text-center" style="margin-bottom: 6px;">
        <div class="font-bold" style="font-size: 13px; text-transform: uppercase;">
            <?= Html::encode($hospital['name'] ?? 'RUMAH SAKIT') ?>
        </div>
        <div style="font-size: 10px; color: #4a5568;">
            <?= Html::encode($hospital['address'] ?? '') ?>
        </div>
        <div style="font-size: 10px; color: #4a5568;">
            Telp: <?= Html::encode($hospital['phone'] ?? '-') ?>
        </div>
    </div>

    <div class="divider"></div>

    <?php if (!empty($print['is_copy'])): ?>
    <div class="text-center" style="margin: 4px 0;">
        <span class="badge-copy"><?= Html::encode($print['copy_text']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Judul & Info Bukti Pembayaran -->
    <div class="text-center font-bold" style="font-size: 13px; margin: 4px 0;">
        BUKTI PEMBAYARAN
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 4px;">
        <tr>
            <td style="width: 85px; color: #4a5568;">No. Bukti</td>
            <td class="font-bold">: <?= Html::encode($header['payment_no'] ?? '') ?></td>
        </tr>
        <tr>
            <td style="color: #4a5568;">Tanggal</td>
            <td>: <?= Html::encode($header['paid_at'] ?? '') ?></td>
        </tr>
        <tr>
            <td style="color: #4a5568;">No. Tagihan</td>
            <td>: <?= Html::encode($header['bill_no'] ?? '') ?></td>
        </tr>
        <tr>
            <td style="color: #4a5568;">Kasir</td>
            <td>: <?= Html::encode($header['cashier_name'] ?? '') ?></td>
        </tr>
        <tr>
            <td style="color: #4a5568;">Pasien</td>
            <td>: <?= Html::encode($patient['full_name'] ?? '') ?></td>
        </tr>
        <tr>
            <td style="color: #4a5568;">No. RM</td>
            <td>: <?= Html::encode($patient['medical_record_no'] ?? '') ?></td>
        </tr>
    </table>

    <div class="divider"></div>

    <!-- Detail Pembayaran -->
    <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
        <tr>
            <td style="width: 85px; color: #4a5568;">Metode</td>
            <td>: <?= Html::encode($payment['method'] ?? 'Tunai') ?></td>
        </tr>
        <?php if (!empty($payment['reference_no'])): ?>
        <tr>
            <td style="color: #4a5568;">No. Kuitansi</td>
            <td>: <?= Html::encode($payment['reference_no']) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td style="color: #4a5568; font-weight: bold; padding-top: 4px;">Jumlah Bayar</td>
            <td style="font-weight: bold; font-size: 12px; padding-top: 4px;">
                : Rp <?= number_format((float)($payment['amount'] ?? 0), 0, ',', '.') ?>
            </td>
        </tr>
    </table>

    <div style="font-size: 10px; font-style: italic; color: #4a5568; margin-top: 4px; padding-left: 2px;">
        (<?= Html::encode($payment['amount_terbilang'] ?? '') ?>)
    </div>

    <div class="divider"></div>

    <!-- Posisi Tagihan Setelah Pembayaran Ini -->
    <div style="font-size: 10px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px; color: #4a5568;">
        Posisi Tagihan:
    </div>
    <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
        <tr>
            <td style="color: #4a5568;">Total Tagihan</td>
            <td class="text-right">Rp <?= number_format((float)($billSummary['total_amount'] ?? 0), 0, ',', '.') ?></td>
        </tr>
        <tr>
            <td style="color: #4a5568;">Total Dibayar</td>
            <td class="text-right">Rp <?= number_format((float)($billSummary['paid_up_to_this'] ?? 0), 0, ',', '.') ?></td>
        </tr>
        <tr style="font-weight: bold;">
            <td style="color: #2d3748;">Sisa Tagihan</td>
            <td class="text-right">Rp <?= number_format((float)($billSummary['remaining'] ?? 0), 0, ',', '.') ?></td>
        </tr>
        <tr>
            <td style="color: #4a5568;">Status</td>
            <td class="text-right font-bold" style="color: <?= ($billSummary['status_label'] ?? '') === 'LUNAS' ? '#22543d' : '#744210' ?>;">
                <?= Html::encode($billSummary['status_label'] ?? '') ?>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    <!-- Footer -->
    <div class="text-center" style="font-size: 9px; color: #718096; margin-top: 6px;">
        <div>Dicetak oleh <?= Html::encode($print['printed_by'] ?? 'Sistem') ?> pada <?= Html::encode($print['printed_at'] ?? date('Y-m-d H:i:s')) ?></div>
        <div>Dokumen ini dihasilkan oleh sistem</div>
        <div style="margin-top: 4px;">*** Terima Kasih ***</div>
    </div>
</div>
