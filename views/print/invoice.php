<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array $data */

use yii\helpers\Html;

$hospital = $data['hospital'] ?? [];
$header   = $data['header'] ?? [];
$patient  = $data['patient'] ?? [];
$groups   = $data['groups'] ?? [];
$summary  = $data['summary'] ?? [];
$payments = $data['payments'] ?? [];
$print    = $data['print'] ?? [];

$statusClass = match ($summary['payment_status'] ?? '') {
    'PAID'    => 'badge-lunas',
    'PARTIAL' => 'badge-partial',
    default   => 'badge-unpaid',
};
?>
<div class="invoice-sheet">
    <?php if (!empty($print['is_copy'])): ?>
    <div class="text-right">
        <span class="badge-copy"><?= Html::encode($print['copy_text']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Header / Kop Surat -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; border-bottom: 2px solid #2d3748; padding-bottom: 10px;">
        <div>
            <h1 style="font-size: 18px; font-weight: 800; color: #2d3748; text-transform: uppercase; margin-bottom: 4px;">
                <?= Html::encode($hospital['name'] ?? 'RUMAH SAKIT') ?>
            </h1>
            <div style="font-size: 11px; color: #4a5568;">
                <div><?= Html::encode($hospital['address'] ?? '') ?></div>
                <div>Telp: <?= Html::encode($hospital['phone'] ?? '-') ?> | Email: <?= Html::encode($hospital['email'] ?? '-') ?></div>
            </div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 20px; font-weight: 900; color: #2b6cb0; letter-spacing: 1px;">INVOICE</div>
            <div style="font-size: 13px; font-weight: 700; color: #2d3748;"><?= Html::encode($header['bill_no'] ?? '') ?></div>
        </div>
    </div>

    <!-- Metadata Kunjungan & Pasien (2 Kolom) -->
    <div style="display: flex; justify-content: space-between; margin-bottom: 16px; background-color: #edf2f7; padding: 10px 14px; border-radius: 4px; font-size: 12px;">
        <div style="width: 48%;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 130px; color: #718096; padding: 2px 0;">No. Tagihan</td>
                    <td style="font-weight: 600; padding: 2px 0;">: <?= Html::encode($header['bill_no'] ?? '') ?></td>
                </tr>
                <tr>
                    <td style="color: #718096; padding: 2px 0;">Tanggal Terbit</td>
                    <td style="font-weight: 600; padding: 2px 0;">: <?= Html::encode($header['issued_at'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td style="color: #718096; padding: 2px 0;">No. Kunjungan</td>
                    <td style="font-weight: 600; padding: 2px 0;">: <?= Html::encode($header['visit_no'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td style="color: #718096; padding: 2px 0;">Tanggal Kunjungan</td>
                    <td style="font-weight: 600; padding: 2px 0;">: <?= Html::encode($header['visit_date'] ?? '-') ?></td>
                </tr>
            </table>
        </div>
        <div style="width: 48%;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 130px; color: #718096; padding: 2px 0;">No. Rekam Medis</td>
                    <td style="font-weight: 600; padding: 2px 0;">: <?= Html::encode($patient['medical_record_no'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td style="color: #718096; padding: 2px 0;">Nama Pasien</td>
                    <td style="font-weight: 600; padding: 2px 0;">: <?= Html::encode($patient['full_name'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td style="color: #718096; padding: 2px 0;">Poli / Unit</td>
                    <td style="font-weight: 600; padding: 2px 0;">: <?= Html::encode($header['poli_name'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td style="color: #718096; padding: 2px 0;">Dokter Pemeriksa</td>
                    <td style="font-weight: 600; padding: 2px 0;">: <?= Html::encode($header['doctor_name'] ?? '-') ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Tabel Rincian Biaya per Kelompok -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
        <thead>
            <tr style="background-color: #2d3748; color: #fff; font-size: 11px; text-transform: uppercase;">
                <th style="padding: 7px 8px; width: 40px; text-align: center;">No</th>
                <th style="padding: 7px 8px; text-align: left;">Uraian Biaya</th>
                <th style="padding: 7px 8px; width: 60px; text-align: center;">Qty</th>
                <th style="padding: 7px 8px; width: 110px; text-align: right;">Harga (Rp)</th>
                <th style="padding: 7px 8px; width: 120px; text-align: right;">Subtotal (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $grp): ?>
                <!-- Group Header -->
                <tr style="background-color: #e2e8f0; font-weight: 700; font-size: 11px;">
                    <td colspan="5" style="padding: 6px 8px; color: #2d3748; text-transform: uppercase;">
                        <?= Html::encode($grp['label']) ?>
                    </td>
                </tr>
                <!-- Group Items -->
                <?php foreach ($grp['items'] as $it): ?>
                <tr style="border-bottom: 1px solid #edf2f7; font-size: 12px;">
                    <td style="padding: 6px 8px; text-align: center; color: #718096;"><?= (int)$it['no'] ?></td>
                    <td style="padding: 6px 8px;"><?= Html::encode($it['description']) ?></td>
                    <td style="padding: 6px 8px; text-align: center;"><?= (int)$it['qty'] ?></td>
                    <td style="padding: 6px 8px; text-align: right;"><?= number_format((float)$it['unit_price'], 0, ',', '.') ?></td>
                    <td style="padding: 6px 8px; text-align: right; font-weight: 500;"><?= number_format((float)$it['subtotal'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                <!-- Group Subtotal -->
                <tr style="border-bottom: 1px solid #cbd5e0; font-weight: 600; font-size: 11px; background-color: #f7fafc;">
                    <td colspan="4" style="padding: 5px 8px; text-align: right; color: #4a5568;">
                        Subtotal <?= Html::encode($grp['label']) ?>:
                    </td>
                    <td style="padding: 5px 8px; text-align: right; color: #2d3748;">
                        <?= number_format((float)$grp['subtotal'], 0, ',', '.') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Ringkasan Tagihan & Terbilang -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
        <!-- Terbilang & Status -->
        <div style="width: 55%; padding-right: 15px;">
            <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600; margin-bottom: 2px;">Terbilang:</div>
            <div style="font-size: 12px; font-style: italic; background-color: #edf2f7; padding: 8px 12px; border-radius: 4px; color: #2d3748; line-height: 1.4; margin-bottom: 12px;">
                # <?= Html::encode(ucfirst((string)($summary['total_terbilang'] ?? ''))) ?> #
            </div>

            <div>
                <span style="font-size: 11px; color: #718096; font-weight: 600; text-transform: uppercase;">Status Tagihan: </span>
                <span class="badge-status <?= $statusClass ?>"><?= Html::encode($summary['status_label'] ?? '') ?></span>
            </div>
        </div>

        <!-- Tabel Total -->
        <div style="width: 42%;">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 6px 0; color: #4a5568;">Total Tagihan</td>
                    <td style="padding: 6px 0; text-align: right; font-weight: 700; font-size: 13px;">
                        Rp <?= number_format((float)($summary['total_amount'] ?? 0), 0, ',', '.') ?>
                    </td>
                </tr>
                <tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 6px 0; color: #4a5568;">Total Dibayar</td>
                    <td style="padding: 6px 0; text-align: right; font-weight: 600; color: #22543d;">
                        Rp <?= number_format((float)($summary['paid_amount'] ?? 0), 0, ',', '.') ?>
                    </td>
                </tr>
                <tr style="border-top: 2px solid #2d3748;">
                    <td style="padding: 8px 0; font-weight: 700; color: #2d3748;">Sisa Tagihan</td>
                    <td style="padding: 8px 0; text-align: right; font-weight: 800; font-size: 14px; color: #c53030;">
                        Rp <?= number_format((float)($summary['outstanding'] ?? 0), 0, ',', '.') ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Riwayat Pembayaran (Bila ada) -->
    <?php if (!empty($payments)): ?>
    <div style="margin-bottom: 24px;">
        <div style="font-size: 11px; font-weight: 700; color: #4a5568; text-transform: uppercase; margin-bottom: 6px;">
            Riwayat Pembayaran
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
            <thead>
                <tr style="background-color: #edf2f7; color: #4a5568; border-bottom: 1px solid #cbd5e0;">
                    <th style="padding: 4px 6px; text-align: center; width: 30px;">No</th>
                    <th style="padding: 4px 6px; text-align: left;">No. Pembayaran</th>
                    <th style="padding: 4px 6px; text-align: left;">Tanggal</th>
                    <th style="padding: 4px 6px; text-align: center;">Metode</th>
                    <th style="padding: 4px 6px; text-align: right;">Jumlah (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $idx => $p): ?>
                <tr style="border-bottom: 1px solid #edf2f7;">
                    <td style="padding: 4px 6px; text-align: center; color: #718096;"><?= $idx + 1 ?></td>
                    <td style="padding: 4px 6px;"><?= Html::encode($p['payment_no']) ?></td>
                    <td style="padding: 4px 6px;"><?= Html::encode($p['paid_at']) ?></td>
                    <td style="padding: 4px 6px; text-align: center;"><?= Html::encode($p['method']) ?></td>
                    <td style="padding: 4px 6px; text-align: right; font-weight: 500;"><?= number_format((float)$p['amount'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Footer Dokumen -->
    <div style="margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 8px; display: flex; justify-content: space-between; font-size: 10px; color: #a0aec0;">
        <div>Dicetak oleh <?= Html::encode($print['printed_by'] ?? 'Sistem') ?> pada <?= Html::encode($print['printed_at'] ?? date('Y-m-d H:i:s')) ?></div>
        <div>Dokumen ini dihasilkan oleh sistem</div>
    </div>
</div>
