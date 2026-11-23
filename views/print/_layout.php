<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $content */
/** @var string $title */
/** @var string $pageType 'a4'|'receipt80' */
/** @var bool $autoprint */

use yii\helpers\Html;

$pageType = $pageType ?? 'a4';
$autoprint = $autoprint ?? false;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Html::encode($title ?? 'Cetak Dokumen') ?></title>
    <style>
        /* Reset and Base Styles */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 13px;
            color: #1a202c;
            background-color: #f7fafc;
            line-height: 1.4;
        }

        /* Screen Toolbar */
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 48px;
            background-color: #2d3748;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 9999;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .toolbar-title {
            font-weight: 600;
            font-size: 14px;
        }

        .toolbar-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            display: inline-block;
            font-weight: 500;
            text-align: center;
            cursor: pointer;
            padding: 6px 14px;
            font-size: 13px;
            border-radius: 4px;
            border: none;
            transition: background-color 0.15s ease;
            text-decoration: none;
        }

        .btn-primary {
            background-color: #3182ce;
            color: #fff;
        }
        .btn-primary:hover {
            background-color: #2b6cb0;
        }

        .btn-secondary {
            background-color: #718096;
            color: #fff;
        }
        .btn-secondary:hover {
            background-color: #4a5568;
        }

        .page-container {
            margin-top: 60px;
            margin-bottom: 40px;
            display: flex;
            justify-content: center;
        }

        /* A4 Page Styles */
        .invoice-sheet {
            background-color: #fff;
            width: 210mm;
            min-height: 297mm;
            padding: 15mm;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
        }

        /* 80mm Receipt Styles */
        .receipt-sheet {
            background-color: #fff;
            width: 80mm;
            min-height: 100mm;
            padding: 5mm;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }

        .divider {
            border-bottom: 1px dashed #718096;
            margin: 8px 0;
        }
        .divider-solid {
            border-bottom: 2px solid #2d3748;
            margin: 12px 0;
        }

        .badge-copy {
            display: inline-block;
            background-color: #fed7d7;
            color: #9b2c2c;
            border: 1px solid #feb2b2;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 4px;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .badge-status {
            display: inline-block;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 4px;
            text-align: center;
        }
        .badge-lunas {
            background-color: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        .badge-partial {
            background-color: #feebc8;
            color: #744210;
            border: 1px solid #fbd38d;
        }
        .badge-unpaid {
            background-color: #fed7d7;
            color: #742a2a;
            border: 1px solid #feb2b2;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: none;
                color: #000;
                font-size: 11pt;
            }

            .toolbar, .no-print {
                display: none !important;
            }

            .page-container {
                margin: 0 !important;
                padding: 0 !important;
                display: block;
            }

            .invoice-sheet {
                width: 100% !important;
                min-height: auto !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            .receipt-sheet {
                width: 100% !important;
                min-height: auto !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            <?php if ($pageType === 'receipt80'): ?>
            @page {
                size: 80mm auto;
                margin: 3mm;
            }
            <?php else: ?>
            @page {
                size: A4 portrait;
                margin: 15mm;
            }
            <?php endif; ?>
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <div class="toolbar-title"><?= Html::encode($title ?? 'Cetak Dokumen') ?></div>
        <div class="toolbar-actions">
            <button onclick="window.print()" class="btn btn-primary">Cetak</button>
            <button onclick="window.close()" class="btn btn-secondary">Tutup</button>
        </div>
    </div>

    <div class="page-container">
        <?= $content ?>
    </div>

    <?php if ($autoprint): ?>
    <script>
        window.addEventListener('load', function() {
            window.print();
        });
    </script>
    <?php endif; ?>
</body>
</html>
