<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\AuditLog;
use app\models\Procedure;
use yii\db\Exception as DbException;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class ProceduresController extends Controller
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
        $query = Procedure::find()->where(['deleted_at' => null]);

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

        return $this->buildPagedResponse($query, $orderBy, fn(Procedure $p) => $this->dataProcedure($p));
    }

    public function actionOptions(): array
    {
        $rows = Procedure::find()
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
        $procedure = Procedure::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$procedure) {
            return [
                'rc'   => 404,
                'rm'   => 'Data tindakan medis tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('procedures', $id, 'VIEW');

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->dataProcedure($procedure),
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
            $exists = Procedure::find()
                ->where(['upper(code)' => $code, 'deleted_at' => null])
                ->exists();
            if ($exists) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode tindakan {$code} sudah digunakan",
                    'data' => null,
                ];
            }
        }

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $procedure = new Procedure();
        $procedure->code = $code;
        $procedure->name = $params['name'] ?? null;
        $procedure->tariff = $params['tariff'] ?? null;
        $procedure->is_active = isset($params['is_active']) ? filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN) : true;
        $procedure->created_at = $now;
        $procedure->created_by = $currentUserId;
        $procedure->updated_at = $now;
        $procedure->updated_by = $currentUserId;

        if (!$procedure->validate()) {
            return [
                'rc'   => 422,
                'rm'   => 'Validasi gagal',
                'data' => $procedure->getErrors(),
            ];
        }

        try {
            $procedure->save(false);
        } catch (DbException $e) {
            if (str_contains($e->getMessage(), 'ux_procedures_code')) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode tindakan {$procedure->code} sudah digunakan",
                    'data' => null,
                ];
            }
            if (str_contains($e->getMessage(), 'procedures_tariff_check')) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Tarif tindakan tidak boleh negatif',
                    'data' => ['tariff' => ['Tarif tindakan tidak boleh negatif']],
                ];
            }
            throw $e;
        }

        $data = $this->dataProcedure($procedure);
        AuditLog::log('procedures', (int)$procedure->id, 'CREATE', null, $data);

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

        $procedure = Procedure::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$procedure) {
            return [
                'rc'   => 404,
                'rm'   => 'Data tindakan medis tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataProcedure($procedure);
        $params = $this->getRequestParams();

        if (isset($params['code'])) {
            $newCode = $this->normalizeCode($params['code']);
            $exists = Procedure::find()
                ->where(['upper(code)' => $newCode, 'deleted_at' => null])
                ->andWhere(['!=', 'id', $id])
                ->exists();
            if ($exists) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode tindakan {$newCode} sudah digunakan",
                    'data' => null,
                ];
            }
            $procedure->code = $newCode;
        }

        if (isset($params['name'])) {
            $procedure->name = $params['name'];
        }
        if (isset($params['tariff'])) {
            $procedure->tariff = $params['tariff'];
        }
        if (isset($params['is_active'])) {
            $procedure->is_active = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $procedure->updated_at = date('Y-m-d H:i:s');
        $procedure->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        if (!$procedure->validate()) {
            return [
                'rc'   => 422,
                'rm'   => 'Validasi gagal',
                'data' => $procedure->getErrors(),
            ];
        }

        try {
            $procedure->save(false);
        } catch (DbException $e) {
            if (str_contains($e->getMessage(), 'ux_procedures_code')) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode tindakan {$procedure->code} sudah digunakan",
                    'data' => null,
                ];
            }
            if (str_contains($e->getMessage(), 'procedures_tariff_check')) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Tarif tindakan tidak boleh negatif',
                    'data' => ['tariff' => ['Tarif tindakan tidak boleh negatif']],
                ];
            }
            throw $e;
        }

        $newValues = $this->dataProcedure($procedure);
        AuditLog::log('procedures', (int)$procedure->id, 'UPDATE', $oldValues, $newValues);

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

        $procedure = Procedure::findOne(['id' => $id, 'deleted_at' => null]);
        if (!$procedure) {
            return [
                'rc'   => 404,
                'rm'   => 'Data tindakan medis tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataProcedure($procedure);
        return $this->performSoftDelete($procedure, 'procedures', $oldValues);
    }

    private function dataProcedure(Procedure $procedure): array
    {
        return [
            'id'         => (int)$procedure->id,
            'code'       => $procedure->code,
            'name'       => $procedure->name,
            'tariff'     => (float)$procedure->tariff,
            'is_active'  => (bool)$procedure->is_active,
            'created_at' => $procedure->created_at,
            'created_by' => $procedure->created_by !== null ? (int)$procedure->created_by : null,
            'updated_at' => $procedure->updated_at,
            'updated_by' => $procedure->updated_by !== null ? (int)$procedure->updated_by : null,
        ];
    }
}
