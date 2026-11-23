<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\models\Menu;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class MenuController extends Controller
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
        $user = Yii::$app->user->identity;
        if (!$user) {
            Yii::$app->response->statusCode = 401;
            return [
                'rc'   => 401,
                'rm'   => 'Unauthorized',
                'data' => null,
            ];
        }

        $roleId = $user->roles ?? $user->role?->id;
        if ($roleId === null) {
            Yii::$app->response->statusCode = 403;
            return [
                'rc'   => 403,
                'rm'   => 'Pengguna tidak memiliki role yang valid',
                'data' => null,
            ];
        }

        $menus = Menu::findByRoleId((int)$roleId);

        $format = strtolower((string)Yii::$app->request->get('format', ''));
        $treeParam = Yii::$app->request->get('tree');

        $isFlat = $format === 'flat' || $treeParam === 'false' || $treeParam === '0';

        $data = $isFlat ? Menu::formatFlat($menus) : Menu::buildTree($menus);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => $data,
        ];
    }
}
