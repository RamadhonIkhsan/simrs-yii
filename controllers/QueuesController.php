<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\DoctorContext;
use app\components\JwtAuth;
use app\components\MasterControllerTrait;
use app\models\Visit;
use DateTime;
use yii\db\Query;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class QueuesController extends Controller
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
                    'index' => ['GET'],
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
        $denied = $this->requireRoles(['ADMIN', 'REGISTRATION', 'DOCTOR']);
        if ($denied !== null) {
            return $denied;
        }

        $request = Yii::$app->request;
        $dateParam = trim((string)$request->get('date', ''));
        $date = $dateParam !== '' ? $dateParam : date('Y-m-d');

        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            return [
                'rc'   => 422,
                'rm'   => 'Format date harus YYYY-MM-DD yang valid',
                'data' => null,
            ];
        }

        $poliIdParam = $request->get('poli_id');
        $includeCancelledParam = $request->get('include_cancelled');
        $includeCancelled = $includeCancelledParam !== null && $includeCancelledParam !== ''
            && filter_var($includeCancelledParam, FILTER_VALIDATE_BOOLEAN);

        $doctorId = DoctorContext::resolveDoctorIdFilter($request->get('doctor_id'));

        $query = (new Query())
            ->select([
                'visit_id'          => 'v.id',
                'v.visit_no',
                'v.queue_no',
                'v.status',
                'v.patient_id',
                'medical_record_no' => 'pt.medical_record_no',
                'patient_name'      => 'pt.full_name',
                'v.doctor_id',
                'doctor_name'       => 'd.full_name',
                'v.poli_id',
                'poli_name'         => 'p.name',
            ])
            ->from(['v' => 'visits'])
            ->innerJoin(['pt' => 'patients'], 'pt.id = v.patient_id')
            ->innerJoin(['d' => 'doctors'], 'd.id = v.doctor_id')
            ->innerJoin(['p' => 'polis'], 'p.id = v.poli_id')
            ->where(['v.visit_date' => $date])
            ->orderBy(['v.queue_no' => SORT_ASC, 'v.id' => SORT_ASC]);

        if ($poliIdParam !== null && $poliIdParam !== '') {
            $query->andWhere(['v.poli_id' => (int)$poliIdParam]);
        }

        if ($doctorId !== null) {
            $query->andWhere(['v.doctor_id' => $doctorId]);
        }

        if (!$includeCancelled) {
            $query->andWhere(['<>', 'v.status', Visit::STATUS_CANCELLED]);
        }

        $rows = $query->all();

        $summary = [
            Visit::STATUS_WAITING   => 0,
            Visit::STATUS_CALLED    => 0,
            Visit::STATUS_EXAMINING => 0,
            Visit::STATUS_DONE      => 0,
            Visit::STATUS_CANCELLED => 0,
            'total'                 => 0,
        ];

        $items = [];
        $current = null;
        $next = null;

        foreach ($rows as $r) {
            $st = (string)$r['status'];
            if (isset($summary[$st])) {
                $summary[$st]++;
            }
            $summary['total']++;

            $item = [
                'queue_no'    => (int)$r['queue_no'],
                'visit_id'    => (int)$r['visit_id'],
                'visit_no'    => $r['visit_no'],
                'status'      => $st,
                'patient'     => [
                    'id'                => (int)$r['patient_id'],
                    'medical_record_no' => $r['medical_record_no'],
                    'full_name'         => $r['patient_name'],
                ],
                'doctor_id'   => (int)$r['doctor_id'],
                'doctor_name' => $r['doctor_name'],
                'poli_id'     => (int)$r['poli_id'],
                'poli_name'   => $r['poli_name'],
            ];

            $items[] = $item;

            if ($current === null && in_array($st, [Visit::STATUS_CALLED, Visit::STATUS_EXAMINING], true)) {
                $current = $item;
            }

            if ($next === null && $st === Visit::STATUS_WAITING) {
                $next = $item;
            }
        }

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => [
                'date'    => $date,
                'summary' => $summary,
                'current' => $current,
                'next'    => $next,
                'queues'  => $items,
            ],
        ];
    }
}
