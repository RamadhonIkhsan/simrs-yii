<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\models\AuditLog;
use app\models\Poli;
use yii\db\IntegrityException;
use yii\db\Query;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class PolisController extends Controller
{
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
                    'index'   => ['GET'],
                    'options' => ['GET'],
                    'view'    => ['GET'],
                    'create'  => ['POST'],
                    'update'  => ['PUT', 'PATCH'],
                    'delete'  => ['DELETE'],
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
        $pageParam = $request->get('page');
        $sizeParam = $request->get('size');
        $search = trim((string)$request->get('search', ''));
        $isActive = $request->get('is_active');
        $sortParam = $request->get('sort');
        $orderParam = strtolower((string)$request->get('order', 'asc'));

        $size = $sizeParam !== null ? (int)$sizeParam : 10;
        if ($size <= 0) {
            $size = 10;
        }

        if ($pageParam === null || (int)$pageParam <= 1) {
            $displayPage = 0;
            $offset = 0;
        } else {
            $displayPage = (int)$pageParam - 1;
            $offset = $displayPage * $size;
        }

        $query = Poli::find();

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'code', $search],
                ['ilike', 'name', $search],
            ]);
        }

        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        $allowedSort = [
            'code' => 'code',
            'name' => 'name',
            'registration_fee' => 'registration_fee',
            'created_at' => 'created_at',
        ];

        $orderBy = ['code' => SORT_ASC];
        if ($sortParam !== null && is_string($sortParam)) {
            $isDesc = str_starts_with($sortParam, '-') || $orderParam === 'desc';
            $cleanSort = ltrim($sortParam, '-');
            if (isset($allowedSort[$cleanSort])) {
                $orderBy = [$allowedSort[$cleanSort] => $isDesc ? SORT_DESC : SORT_ASC];
            }
        }

        $totalElements = (int)(clone $query)->count();
        $totalPages = $size > 0 ? (int)ceil($totalElements / $size) : 0;
        if ($totalPages === 0 && $totalElements === 0) {
            $totalPages = 0;
        }

        $polis = $query->orderBy($orderBy)
            ->limit($size)
            ->offset($offset)
            ->all();

        $data = [];
        foreach ($polis as $poli) {
            $data[] = $this->dataPoli($poli);
        }

        return [
            'page' => $displayPage,
            'size' => $size,
            'totalPages' => $totalPages,
            'totalElements' => $totalElements,
            'data' => $data,
        ];
    }

    public function actionOptions(): array
    {
        $polis = Poli::find()
            ->select(['id', 'code', 'name', 'registration_fee'])
            ->where(['is_active' => true])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        $data = [];
        foreach ($polis as $p) {
            $data[] = [
                'id' => (int)$p['id'],
                'code' => $p['code'],
                'name' => $p['name'],
                'registration_fee' => (float)$p['registration_fee'],
            ];
        }

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionView(int $id): array
    {
        $poli = Poli::findOne(['id' => $id]);
        if (!$poli) {
            return [
                'rc' => 404,
                'rm' => 'Data poli tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('polis', $id, 'VIEW');

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $this->dataPoli($poli),
        ];
    }

    public function actionCreate(): array
    {
        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        $code = strtoupper(trim((string)($params['code'] ?? '')));

        if ($code !== '') {
            $exists = Poli::find()
                ->where(['upper(code)' => $code])
                ->exists();
            if ($exists) {
                return [
                    'rc' => 409,
                    'rm' => "Kode poli {$code} sudah digunakan",
                    'data' => null,
                ];
            }
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $poli = new Poli();
        $poli->code = $code;
        $poli->name = trim((string)($params['name'] ?? ''));
        $poli->registration_fee = isset($params['registration_fee']) ? (float)$params['registration_fee'] : 0.0;
        $poli->is_active = isset($params['is_active']) ? filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN) : true;
        $poli->created_at = $now;
        $poli->created_by = $currentUserId;
        $poli->updated_at = $now;
        $poli->updated_by = $currentUserId;

        try {
            if (!$poli->save()) {
                return [
                    'rc' => 422,
                    'rm' => 'Validasi gagal',
                    'data' => $poli->getErrors(),
                ];
            }
        } catch (IntegrityException $e) {
            if (str_contains($e->getMessage(), 'ux_polis_code')) {
                return [
                    'rc' => 409,
                    'rm' => "Kode poli {$code} sudah digunakan",
                    'data' => null,
                ];
            }
            throw $e;
        }

        $data = $this->dataPoli($poli);
        AuditLog::log('polis', (int)$poli->id, 'CREATE', null, $data);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionUpdate(int $id): array
    {
        $poli = Poli::findOne(['id' => $id]);
        if (!$poli) {
            return [
                'rc' => 404,
                'rm' => 'Data poli tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataPoli($poli);

        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        if (isset($params['code'])) {
            $newCode = strtoupper(trim((string)$params['code']));
            $exists = Poli::find()
                ->where(['upper(code)' => $newCode])
                ->andWhere(['!=', 'id', $id])
                ->exists();
            if ($exists) {
                return [
                    'rc' => 409,
                    'rm' => "Kode poli {$newCode} sudah digunakan",
                    'data' => null,
                ];
            }
            $poli->code = $newCode;
        }

        if (isset($params['name'])) {
            $poli->name = trim((string)$params['name']);
        }

        if (isset($params['registration_fee'])) {
            $poli->registration_fee = (float)$params['registration_fee'];
        }

        if (isset($params['is_active'])) {
            $poli->is_active = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $poli->updated_at = date('Y-m-d H:i:s');
        $poli->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        try {
            if (!$poli->save()) {
                return [
                    'rc' => 422,
                    'rm' => 'Validasi gagal',
                    'data' => $poli->getErrors(),
                ];
            }
        } catch (IntegrityException $e) {
            if (str_contains($e->getMessage(), 'ux_polis_code')) {
                return [
                    'rc' => 409,
                    'rm' => "Kode poli {$poli->code} sudah digunakan",
                    'data' => null,
                ];
            }
            throw $e;
        }

        $newValues = $this->dataPoli($poli);
        AuditLog::log('polis', (int)$poli->id, 'UPDATE', $oldValues, $newValues);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $newValues,
        ];
    }

    public function actionDelete(int $id): array
    {
        $poli = Poli::findOne(['id' => $id]);
        if (!$poli) {
            return [
                'rc' => 404,
                'rm' => 'Data poli tidak ditemukan',
                'data' => null,
            ];
        }

        $hasActiveSchedule = (new Query())
            ->from('doctor_schedules')
            ->where(['poli_id' => $id, 'is_active' => true])
            ->exists();

        if ($hasActiveSchedule) {
            return [
                'rc' => 409,
                'rm' => 'Poli masih memiliki jadwal praktik aktif',
                'data' => null,
            ];
        }

        $oldValues = $this->dataPoli($poli);

        try {
            $poli->delete();
        } catch (\yii\db\IntegrityException $e) {
            return [
                'rc'   => 409,
                'rm'   => 'Data poli tidak dapat dihapus karena masih terkait dengan data lain',
                'data' => null,
            ];
        }

        AuditLog::log('polis', (int)$poli->id, 'DELETE', $oldValues, null);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => null,
        ];
    }

    private function dataPoli(Poli $poli): array
    {
        return [
            'id' => (int)$poli->id,
            'code' => $poli->code,
            'name' => $poli->name,
            'registration_fee' => (float)$poli->registration_fee,
            'is_active' => (bool)$poli->is_active,
            'created_at' => $poli->created_at,
            'created_by' => $poli->created_by !== null ? (int)$poli->created_by : null,
            'updated_at' => $poli->updated_at,
            'updated_by' => $poli->updated_by !== null ? (int)$poli->updated_by : null,
        ];
    }
}
