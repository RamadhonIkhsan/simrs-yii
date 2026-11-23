<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\AuditLog;
use app\models\Icd10;
use yii\db\Exception as DbException;
use yii\db\Query;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class Icd10Controller extends Controller
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
        $search = trim((string)$request->get('search', ''));

        if ($search !== '' && mb_strlen($search) < 2) {
            return [
                'rc'   => 422,
                'rm'   => 'Pencarian ICD-10 minimal 2 karakter',
                'data' => null,
            ];
        }

        $query = Icd10::find();
        $this->applyCodeNameSearch($query, $search, null, false);

        $orderBy = $this->resolveSortOrder([
            'code' => 'code',
            'name' => 'name',
        ], ['code' => SORT_ASC]);

        return $this->buildPagedResponse($query, $orderBy, fn(Icd10 $i) => $this->dataIcd10($i));
    }

    public function actionOptions(): array
    {
        $search = trim((string)Yii::$app->request->get('search', ''));
        if (mb_strlen($search) < 2) {
            return [
                'rc'   => 422,
                'rm'   => 'Parameter search pada options ICD-10 wajib diisi minimal 2 karakter',
                'data' => null,
            ];
        }

        $rows = Icd10::find()
            ->select(['code', 'name'])
            ->where([
                'or',
                ['ilike', 'code', $search],
                ['ilike', 'name', $search],
            ])
            ->orderBy(['code' => SORT_ASC])
            ->limit(20)
            ->asArray()
            ->all();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'code' => $r['code'],
                'name' => $r['name'],
            ];
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionView(string $code): array
    {
        $normalizedCode = $this->normalizeCode($code);
        $icd = Icd10::findOne(['code' => $normalizedCode]);

        if (!$icd) {
            return [
                'rc'   => 404,
                'rm'   => 'Kode ICD-10 tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('icd10', 0, 'VIEW', null, $this->dataIcd10($icd));

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $this->dataIcd10($icd),
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
            $exists = Icd10::find()->where(['code' => $code])->exists();
            if ($exists) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode ICD-10 {$code} sudah terdaftar",
                    'data' => null,
                ];
            }
        }

        $icd = new Icd10();
        $icd->code = $code;
        $icd->name = $params['name'] ?? null;

        if (!$icd->validate()) {
            return [
                'rc'   => 422,
                'rm'   => 'Validasi gagal',
                'data' => $icd->getErrors(),
            ];
        }

        try {
            $icd->save(false);
        } catch (DbException $e) {
            if (str_contains($e->getMessage(), 'icd10_pkey')) {
                return [
                    'rc'   => 409,
                    'rm'   => "Kode ICD-10 {$icd->code} sudah terdaftar",
                    'data' => null,
                ];
            }
            throw $e;
        }

        $data = $this->dataIcd10($icd);
        AuditLog::log('icd10', 0, 'CREATE', null, $data);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }

    public function actionUpdate(string $code): array
    {
        $denied = $this->requireRoles(['ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $normalizedCode = $this->normalizeCode($code);
        $icd = Icd10::findOne(['code' => $normalizedCode]);

        if (!$icd) {
            return [
                'rc'   => 404,
                'rm'   => 'Kode ICD-10 tidak ditemukan',
                'data' => null,
            ];
        }

        $params = $this->getRequestParams();
        if (array_key_exists('code', $params) && $params['code'] !== null) {
            $sentCode = $this->normalizeCode($params['code']);
            if ($sentCode !== '' && $sentCode !== $icd->code) {
                return [
                    'rc'   => 422,
                    'rm'   => 'Kode ICD-10 tidak boleh diubah',
                    'data' => ['code' => ['Kode ICD-10 tidak boleh diubah']],
                ];
            }
        }

        $oldValues = $this->dataIcd10($icd);

        if (array_key_exists('name', $params)) {
            $icd->name = $params['name'];
        }

        if (!$icd->validate()) {
            return [
                'rc'   => 422,
                'rm'   => 'Validasi gagal',
                'data' => $icd->getErrors(),
            ];
        }

        $icd->save(false);

        $newValues = $this->dataIcd10($icd);
        AuditLog::log('icd10', 0, 'UPDATE', $oldValues, $newValues);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $newValues,
        ];
    }

    public function actionDelete(string $code): array
    {
        $denied = $this->requireRoles(['ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $normalizedCode = $this->normalizeCode($code);
        $icd = Icd10::findOne(['code' => $normalizedCode]);

        if (!$icd) {
            return [
                'rc'   => 404,
                'rm'   => 'Kode ICD-10 tidak ditemukan',
                'data' => null,
            ];
        }

        $usedInDiagnoses = (new Query())
            ->from('diagnoses')
            ->where(['icd10_code' => $icd->code])
            ->exists();

        if ($usedInDiagnoses) {
            return [
                'rc'   => 409,
                'rm'   => 'Kode ICD-10 sudah dipakai pada diagnosis',
                'data' => null,
            ];
        }

        $oldValues = $this->dataIcd10($icd);

        try {
            $icd->delete();
        } catch (DbException $e) {
            if (str_contains($e->getMessage(), 'diagnoses_icd10_code_fkey')) {
                return [
                    'rc'   => 409,
                    'rm'   => 'Kode ICD-10 sudah dipakai pada diagnosis',
                    'data' => null,
                ];
            }
            throw $e;
        }

        AuditLog::log('icd10', 0, 'DELETE', $oldValues, null);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => null,
        ];
    }

    private function dataIcd10(Icd10 $icd): array
    {
        return [
            'code' => $icd->code,
            'name' => $icd->name,
        ];
    }
}
