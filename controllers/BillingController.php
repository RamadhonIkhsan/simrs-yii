<?php

declare(strict_types=1);

namespace app\controllers;

use DateTime;
use Yii;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\Visit;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class BillingController extends Controller
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
                    'queue' => ['GET'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function actionQueue(): array
    {
        $denied = $this->requireRoles(['CASHIER', 'ADMIN']);
        if ($denied !== null) {
            return $denied;
        }

        $dateParam = trim((string)Yii::$app->request->get('date', ''));
        $date = $dateParam !== '' ? $dateParam : date('Y-m-d');

        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            Yii::$app->response->statusCode = 422;
            return [
                'rc'   => 422,
                'rm'   => 'Format date harus YYYY-MM-DD yang valid',
                'data' => null,
            ];
        }

        $q = trim((string)Yii::$app->request->get('q', ''));

        $query = (new \yii\db\Query())
            ->select([
                'visit_id'                 => 'v.id',
                'v.visit_no',
                'v.visit_date',
                'patient_id'               => 'pt.id',
                'medical_record_no'        => 'pt.medical_record_no',
                'patient_name'             => 'pt.full_name',
                'poli_id'                  => 'p.id',
                'poli_code'                => 'p.code',
                'poli_name'                => 'p.name',
                'bill_id'                  => 'b.id',
                'bill_status'              => 'b.status',
                'has_pending_prescription' => new \yii\db\Expression("EXISTS (SELECT 1 FROM prescriptions rx WHERE rx.visit_id = v.id AND rx.status IN ('PENDING', 'PROCESSING'))"),
            ])
            ->from(['v' => 'visits'])
            ->innerJoin(['pt' => 'patients'], 'pt.id = v.patient_id')
            ->innerJoin(['p' => 'polis'], 'p.id = v.poli_id')
            ->leftJoin(['b' => 'bills'], 'b.visit_id = v.id')
            ->where([
                'v.status'     => Visit::STATUS_DONE,
                'v.visit_date' => $date,
            ]);

        if ($q !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'v.visit_no', $q],
                ['ilike', 'pt.full_name', $q],
                ['ilike', 'pt.medical_record_no', $q],
            ]);
        }

        $rows = $query->orderBy(['v.queue_no' => SORT_ASC, 'v.id' => SORT_ASC])->all();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'visit_id'             => (int)$r['visit_id'],
                'visit_no'             => $r['visit_no'],
                'patient'              => [
                    'medical_record_no' => $r['medical_record_no'],
                    'full_name'         => $r['patient_name'],
                ],
                'poli'                 => [
                    'id'   => (int)$r['poli_id'],
                    'code' => $r['poli_code'],
                    'name' => $r['poli_name'],
                ],
                'bill_status'          => $r['bill_status'],
                'bill_id'              => $r['bill_id'] !== null ? (int)$r['bill_id'] : null,
                'pending_prescription' => (bool)$r['has_pending_prescription'],
            ];
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }
}
