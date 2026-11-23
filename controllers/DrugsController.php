<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\AuditLog;
use app\models\Drug;
use app\models\StockMovement;
use Throwable;
use yii\db\Exception as DbException;
use yii\db\Expression;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class DrugsController extends Controller
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
                    'index'             => ['GET'],
                    'options'           => ['GET'],
                    'view'              => ['GET'],
                    'create'            => ['POST'],
                    'update'            => ['PUT', 'PATCH'],
                    'delete'            => ['DELETE'],
                    'stock-adjustments' => ['POST'],
                    'stock-movements'   => ['GET'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Drug::find()->where(['deleted_at' => null]);

        $this->applyCodeNameSearch(
            $query,
            (string)$request->get('search', ''),
            $request->get('is_active')
        );

        $lowStock = $request->get('low_stock');
        if ($lowStock !== null && $lowStock !== '' && filter_var($lowStock, FILTER_VALIDATE_BOOLEAN)) {
            $query->andWhere(new Expression('stock <= min_stock'));
        }

        $orderBy = $this->resolveSortOrder([
            'code'       => 'code',
            'name'       => 'name',
            'price'      => 'price',
            'created_at' => 'created_at',
        ], ['code' => SORT_ASC]);

        return $this->buildPagedResponse($query, $orderBy, fn(Drug $d) => $this->dataDrug($d));
    }

    public function actionOptions(): array
    {
        $rows = Drug::find()
            ->select(['id', 'code', 'name', 'unit', 'price', 'stock'])
            ->where(['is_active' => true, 'deleted_at' => null])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id'    => (int)$r['id'],
                'code'  => $r['code'],
                'name'  => $r['name'],
                'unit'  => $r['unit'],
                'price' => (float)$r['price'],
                'stock' => (int)$r['stock'],
            ];
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionView(int $id): array
    {
        $drug = Drug::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$drug) {
            return [
                'rc'   => 404,
                'rm'   => 'Data obat tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('drugs', $id, 'VIEW');

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->dataDrug($drug),
        ];
    }

    public function actionCreate(): array
    {
        $denied = $this->requireRoles(['ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $params = $this->getRequestParams();
        $code = $this->normalizeCode($params['code'] ?? '');

        if ($code !== '') {
            $exists = Drug::find()
                ->where(['upper(code)' => $code, 'deleted_at' => null])
                ->exists();
            if ($exists) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode obat {$code} sudah digunakan",
                    'data' => null,
                ];
            }
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $drug = new Drug();
        $drug->code = $code;
        $drug->name = $params['name'] ?? null;
        $drug->unit = $params['unit'] ?? null;
        $drug->price = $params['price'] ?? null;
        $drug->stock = isset($params['stock']) && $params['stock'] !== '' ? $params['stock'] : 0;
        $drug->min_stock = isset($params['min_stock']) && $params['min_stock'] !== '' ? $params['min_stock'] : 0;
        $drug->is_active = isset($params['is_active']) ? filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN) : true;
        $drug->created_at = $now;
        $drug->created_by = $currentUserId;
        $drug->updated_at = $now;
        $drug->updated_by = $currentUserId;

        if (!$drug->validate()) {
            return [
                'rc'   => 422,
                'rm'   => 'Validasi gagal',
                'data' => $drug->getErrors(),
            ];
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            $drug->save(false);

            $initialStock = (int)$drug->stock;
            if ($initialStock > 0) {
                $movement = new StockMovement();
                $movement->drug_id = (int)$drug->id;
                $movement->movement_type = 'IN';
                $movement->qty_change = $initialStock;
                $movement->stock_after = $initialStock;
                $movement->notes = 'Stok awal';
                $movement->created_at = $now;
                $movement->created_by = $currentUserId;
                $movement->save(false);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            if ($e instanceof DbException) {
                if (str_contains($e->getMessage(), 'ux_drugs_code')) {
                    return [
                        'rc'   => 409,
                        'rm'   => "Kode obat {$drug->code} sudah digunakan",
                        'data' => null,
                    ];
                }
                if (str_contains($e->getMessage(), 'ck_drugs_stock_nonneg')) {
                    return [
                        'rc'   => 422,
                        'rm'   => 'Stok tidak boleh negatif',
                        'data' => ['stock' => ['Stok tidak boleh negatif']],
                    ];
                }
                if (str_contains($e->getMessage(), 'drugs_price_check')) {
                    return [
                        'rc'   => 422,
                        'rm'   => 'Harga obat tidak boleh negatif',
                        'data' => ['price' => ['Harga obat tidak boleh negatif']],
                    ];
                }
            }
            throw $e;
        }

        $data = $this->dataDrug($drug);
        AuditLog::log('drugs', (int)$drug->id, 'CREATE', null, $data);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionUpdate(int $id): array
    {
        $denied = $this->requireRoles(['ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $drug = Drug::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$drug) {
            return [
                'rc'   => 404,
                'rm'   => 'Data obat tidak ditemukan',
                'data' => null,
            ];
        }

        $params = $this->getRequestParams();

        if (array_key_exists('stock', $params) && $params['stock'] !== null && $params['stock'] !== '') {
            if (!is_numeric($params['stock']) || (int)$params['stock'] !== (int)$drug->stock) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Ubah stok melalui endpoint penyesuaian stok',
                    'data' => ['stock' => ['Ubah stok melalui endpoint penyesuaian stok']],
                ];
            }
        }

        $oldValues = $this->dataDrug($drug);

        if (isset($params['code'])) {
            $newCode = $this->normalizeCode($params['code']);
            $exists = Drug::find()
                ->where(['upper(code)' => $newCode, 'deleted_at' => null])
                ->andWhere(['!=', 'id', $id])
                ->exists();
            if ($exists) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode obat {$newCode} sudah digunakan",
                    'data' => null,
                ];
            }
            $drug->code = $newCode;
        }

        if (isset($params['name'])) {
            $drug->name = $params['name'];
        }
        if (isset($params['unit'])) {
            $drug->unit = $params['unit'];
        }
        if (isset($params['price'])) {
            $drug->price = $params['price'];
        }
        if (isset($params['min_stock'])) {
            $drug->min_stock = $params['min_stock'];
        }
        if (isset($params['is_active'])) {
            $drug->is_active = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $drug->updated_at = date('Y-m-d H:i:s');
        $drug->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        if (!$drug->validate()) {
            return [
                'rc'   => 422,
                'rm'   => 'Validasi gagal',
                'data' => $drug->getErrors(),
            ];
        }

        try {
            $drug->save(false);
        } catch (DbException $e) {
            if (str_contains($e->getMessage(), 'ux_drugs_code')) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode obat {$drug->code} sudah digunakan",
                    'data' => null,
                ];
            }
            if (str_contains($e->getMessage(), 'drugs_price_check')) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Harga obat tidak boleh negatif',
                    'data' => ['price' => ['Harga obat tidak boleh negatif']],
                ];
            }
            throw $e;
        }

        $newValues = $this->dataDrug($drug);
        AuditLog::log('drugs', (int)$drug->id, 'UPDATE', $oldValues, $newValues);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $newValues,
        ];
    }

    public function actionDelete(int $id): array
    {
        $denied = $this->requireRoles(['ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $drug = Drug::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$drug) {
            return [
                'rc'   => 404,
                'rm'   => 'Data obat tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataDrug($drug);
        return $this->performSoftDelete($drug, 'drugs', $oldValues);
    }

    public function actionStockAdjustments(int $id): array
    {
        $denied = $this->requireRoles(['ADMIN', 'PHARMACY']);
        if ($denied !== null) {
            return $denied;
        }

        $params = $this->getRequestParams();
        $movementType = strtoupper(trim((string)($params['movement_type'] ?? '')));
        $qtyRaw = $params['qty_change'] ?? null;
        $notes = trim((string)($params['notes'] ?? ''));

        if (!in_array($movementType, ['IN', 'ADJUSTMENT'], true)) {
            return [
                'rc'   => 422,
                'rm'   => 'movement_type harus bernilai IN atau ADJUSTMENT',
                'data' => ['movement_type' => ['movement_type harus bernilai IN atau ADJUSTMENT']],
            ];
        }

        if ($qtyRaw === null || $qtyRaw === '' || filter_var($qtyRaw, FILTER_VALIDATE_INT) === false) {
            return [
                'rc'   => 422,
                'rm'   => 'qty_change harus berupa bilangan bulat',
                'data' => ['qty_change' => ['qty_change harus berupa bilangan bulat']],
            ];
        }

        $qtyChange = (int)$qtyRaw;
        if ($qtyChange === 0) {
            return [
                'rc'   => 422,
                'rm'   => 'qty_change tidak boleh 0',
                'data' => ['qty_change' => ['qty_change tidak boleh 0']],
            ];
        }

        if ($movementType === 'IN' && $qtyChange <= 0) {
            return [
                'rc'   => 422,
                'rm'   => 'Untuk movement_type IN, qty_change harus lebih besar dari 0',
                'data' => ['qty_change' => ['Untuk movement_type IN, qty_change harus lebih besar dari 0']],
            ];
        }

        if ($movementType === 'ADJUSTMENT' && $notes === '') {
            return [
                'rc'   => 422,
                'rm'   => 'Catatan (notes) wajib diisi untuk penyesuaian stok (ADJUSTMENT)',
                'data' => ['notes' => ['Catatan (notes) wajib diisi untuk ADJUSTMENT']],
            ];
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            $newStock = $db->createCommand("
                UPDATE drugs
                SET stock = stock + :qty,
                    updated_at = :now,
                    updated_by = :uid
                WHERE id = :id
                  AND deleted_at IS NULL
                  AND stock + :qty >= 0
                RETURNING stock
            ", [
                ':qty' => $qtyChange,
                ':now' => $now,
                ':uid' => $currentUserId,
                ':id'  => $id,
            ])->queryScalar();

            if ($newStock === false) {
                $transaction->rollBack();

                $exists = Drug::find()->where(['id' => $id, 'deleted_at' => null])->exists();
                if (!$exists) {
                    return [
                        'rc'   => 404,
                        'rm'   => 'Data obat tidak ditemukan',
                        'data' => null,
                    ];
                }

                return [
                    'rc'   => 409,
                    'rm'   => 'Stok tidak mencukupi untuk pengurangan ini',
                    'data' => null,
                ];
            }

            $stockAfter = (int)$newStock;
            $stockBefore = $stockAfter - $qtyChange;

            $movement = new StockMovement();
            $movement->drug_id = $id;
            $movement->movement_type = $movementType;
            $movement->qty_change = $qtyChange;
            $movement->stock_after = $stockAfter;
            $movement->notes = $notes !== '' ? $notes : null;
            $movement->created_at = $now;
            $movement->created_by = $currentUserId;
            $movement->save(false);

            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            if ($e instanceof DbException && str_contains($e->getMessage(), 'ck_drugs_stock_nonneg')) {
                return [
                    'rc'   => 409,
                    'rm'   => 'Stok tidak boleh negatif',
                    'data' => null,
                ];
            }
            throw $e;
        }

        $movementData = $this->dataStockMovement($movement);
        AuditLog::log(
            'drugs',
            $id,
            'STOCK_' . $movementType,
            ['stock' => $stockBefore],
            ['stock' => $stockAfter, 'movement_id' => (int)$movement->id, 'qty_change' => $qtyChange],
            $notes !== '' ? $notes : null
        );

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => [
                'drug_id'       => $id,
                'movement_type' => $movementType,
                'qty_change'    => $qtyChange,
                'stock_before'  => $stockBefore,
                'stock_after'   => $stockAfter,
                'notes'         => $movement->notes,
                'movement'      => $movementData,
            ],
        ];
    }

    public function actionStockMovements(int $id): array
    {
        $drug = Drug::findOne(['id' => $id]);
        if (!$drug) {
            return [
                'rc'   => 404,
                'rm'   => 'Data obat tidak ditemukan',
                'data' => null,
            ];
        }

        $query = StockMovement::find()->where(['drug_id' => $id]);
        $orderBy = ['created_at' => SORT_DESC, 'id' => SORT_DESC];

        return $this->buildPagedResponse($query, $orderBy, fn(StockMovement $m) => $this->dataStockMovement($m));
    }

    private function dataDrug(Drug $drug): array
    {
        return [
            'id'         => (int)$drug->id,
            'code'       => $drug->code,
            'name'       => $drug->name,
            'unit'       => $drug->unit,
            'price'      => (float)$drug->price,
            'stock'      => (int)$drug->stock,
            'min_stock'  => (int)$drug->min_stock,
            'low_stock'  => (int)$drug->stock <= (int)$drug->min_stock,
            'is_active'  => (bool)$drug->is_active,
            'created_at' => $drug->created_at,
            'created_by' => $drug->created_by !== null ? (int)$drug->created_by : null,
            'updated_at' => $drug->updated_at,
            'updated_by' => $drug->updated_by !== null ? (int)$drug->updated_by : null,
        ];
    }

    private function dataStockMovement(StockMovement $m): array
    {
        return [
            'id'            => (int)$m->id,
            'drug_id'       => (int)$m->drug_id,
            'movement_type' => $m->movement_type,
            'qty_change'    => (int)$m->qty_change,
            'stock_after'   => (int)$m->stock_after,
            'ref_type'      => $m->ref_type,
            'ref_id'        => $m->ref_id !== null ? (int)$m->ref_id : null,
            'notes'         => $m->notes,
            'created_at'    => $m->created_at,
            'created_by'    => $m->created_by !== null ? (int)$m->created_by : null,
        ];
    }
}
