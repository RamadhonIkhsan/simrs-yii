<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\AuditLog;
use app\models\LabTest;
use yii\db\Exception as DbException;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class LabTestsController extends Controller
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
        $query = LabTest::find()->where(['deleted_at' => null]);

        $this->applyCodeNameSearch(
            $query,
            (string)$request->get('search', ''),
            $request->get('is_active')
        );

        $orderBy = $this->resolveSortOrder([
            'code'       => 'code',
            'name'       => 'name',
            'tariff'     => 'tariff',
            'created_at' => 'created_at',
        ], ['code' => SORT_ASC]);

        return $this->buildPagedResponse($query, $orderBy, fn(LabTest $l) => $this->dataLabTest($l));
    }

    public function actionOptions(): array
    {
        $rows = LabTest::find()
            ->select(['id', 'code', 'name', 'tariff'])
            ->where(['is_active' => true, 'deleted_at' => null])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id'     => (int)$r['id'],
                'code'   => $r['code'],
                'name'   => $r['name'],
                'tariff' => (float)$r['tariff'],
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
        $labTest = LabTest::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$labTest) {
            return [
                'rc'   => 404,
                'rm'   => 'Data pemeriksaan laboratorium tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('lab_tests', $id, 'VIEW');

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->dataLabTest($labTest),
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
            $exists = LabTest::find()
                ->where(['upper(code)' => $code, 'deleted_at' => null])
                ->exists();
            if ($exists) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode pemeriksaan lab {$code} sudah digunakan",
                    'data' => null,
                ];
            }
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $labTest = new LabTest();
        $labTest->code = $code;
        $labTest->name = $params['name'] ?? null;
        $labTest->unit = $params['unit'] ?? null;
        $labTest->ref_range = $params['ref_range'] ?? null;
        $labTest->tariff = $params['tariff'] ?? null;
        $labTest->is_active = isset($params['is_active']) ? filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN) : true;
        $labTest->created_at = $now;
        $labTest->created_by = $currentUserId;
        $labTest->updated_at = $now;
        $labTest->updated_by = $currentUserId;

        if (!$labTest->validate()) {
            return [
                'rc'   => 422,
                'rm'   => 'Validasi gagal',
                'data' => $labTest->getErrors(),
            ];
        }

        try {
            $labTest->save(false);
        } catch (DbException $e) {
            if (str_contains($e->getMessage(), 'ux_lab_tests_code')) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode pemeriksaan lab {$labTest->code} sudah digunakan",
                    'data' => null,
                ];
            }
            if (str_contains($e->getMessage(), 'lab_tests_tariff_check')) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Tarif pemeriksaan lab tidak boleh negatif',
                    'data' => ['tariff' => ['Tarif pemeriksaan lab tidak boleh negatif']],
                ];
            }
            throw $e;
        }

        $data = $this->dataLabTest($labTest);
        AuditLog::log('lab_tests', (int)$labTest->id, 'CREATE', null, $data);

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

        $labTest = LabTest::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$labTest) {
            return [
                'rc'   => 404,
                'rm'   => 'Data pemeriksaan laboratorium tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataLabTest($labTest);
        $params = $this->getRequestParams();

        if (isset($params['code'])) {
            $newCode = $this->normalizeCode($params['code']);
            $exists = LabTest::find()
                ->where(['upper(code)' => $newCode, 'deleted_at' => null])
                ->andWhere(['!=', 'id', $id])
                ->exists();
            if ($exists) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode pemeriksaan lab {$newCode} sudah digunakan",
                    'data' => null,
                ];
            }
            $labTest->code = $newCode;
        }

        if (isset($params['name'])) {
            $labTest->name = $params['name'];
        }
        if (array_key_exists('unit', $params)) {
            $labTest->unit = $params['unit'];
        }
        if (array_key_exists('ref_range', $params)) {
            $labTest->ref_range = $params['ref_range'];
        }
        if (isset($params['tariff'])) {
            $labTest->tariff = $params['tariff'];
        }
        if (isset($params['is_active'])) {
            $labTest->is_active = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $labTest->updated_at = date('Y-m-d H:i:s');
        $labTest->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        if (!$labTest->validate()) {
            return [
                'rc'   => 422,
                'rm'   => 'Validasi gagal',
                'data' => $labTest->getErrors(),
            ];
        }

        try {
            $labTest->save(false);
        } catch (DbException $e) {
            if (str_contains($e->getMessage(), 'ux_lab_tests_code')) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode pemeriksaan lab {$labTest->code} sudah digunakan",
                    'data' => null,
                ];
            }
            if (str_contains($e->getMessage(), 'lab_tests_tariff_check')) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Tarif pemeriksaan lab tidak boleh negatif',
                    'data' => ['tariff' => ['Tarif pemeriksaan lab tidak boleh negatif']],
                ];
            }
            throw $e;
        }

        $newValues = $this->dataLabTest($labTest);
        AuditLog::log('lab_tests', (int)$labTest->id, 'UPDATE', $oldValues, $newValues);

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

        $labTest = LabTest::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$labTest) {
            return [
                'rc'   => 404,
                'rm'   => 'Data pemeriksaan laboratorium tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataLabTest($labTest);
        return $this->performSoftDelete($labTest, 'lab_tests', $oldValues);
    }

    private function dataLabTest(LabTest $labTest): array
    {
        return [
            'id'         => (int)$labTest->id,
            'code'       => $labTest->code,
            'name'       => $labTest->name,
            'unit'       => $labTest->unit,
            'ref_range'  => $labTest->ref_range,
            'tariff'     => (float)$labTest->tariff,
            'is_active'  => (bool)$labTest->is_active,
            'created_at' => $labTest->created_at,
            'created_by' => $labTest->created_by !== null ? (int)$labTest->created_by : null,
            'updated_at' => $labTest->updated_at,
            'updated_by' => $labTest->updated_by !== null ? (int)$labTest->updated_by : null,
        ];
    }
}
