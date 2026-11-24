<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\components\JwtAuth;
use app\models\AuditLog;
use app\models\User;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class UsersController extends Controller
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
                    'index'  => ['GET'],
                    'view'   => ['GET'],
                    'create' => ['POST'],
                    'update' => ['PUT', 'PATCH'],
                    'status' => ['PUT', 'PATCH', 'POST'],
                    'delete' => ['DELETE'],
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

        $query = User::find()
            ->alias('u')
            ->select(['u.*', 'r.name AS role_name'])
            ->leftJoin('roles r', 'r.id = u.roles');

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'u.username', $search],
                ['ilike', 'u.full_name', $search],
                ['ilike', 'u.email', $search],
                ['ilike', 'r.name', $search],
            ]);
        }

        $totalElements = (int)(clone $query)->count();
        $totalPages = $size > 0 ? (int)ceil($totalElements / $size) : 0;
        if ($totalPages === 0 && $totalElements === 0) {
            $totalPages = 0;
        }

        $users = $query->orderBy(['u.id' => SORT_ASC])
            ->limit($size)
            ->offset($offset)
            ->all();

        $data = [];
        foreach ($users as $user) {
            $data[] = $this->dataUser($user);
        }

        return [
            'page' => $displayPage,
            'size' => $size,
            'totalPages' => $totalPages,
            'totalElements' => $totalElements,
            'data' => $data,
        ];
    }

    public function actionView(int $id): array
    {
        $user = User::find()
            ->alias('u')
            ->select(['u.*', 'r.name AS role_name'])
            ->leftJoin('roles r', 'r.id = u.roles')
            ->where(['u.id' => $id])
            ->one();

        if (!$user) {
            return [
                'rc' => 404,
                'rm' => 'Data user tidak ditemukan',
                'data' => null,
            ];
        }

        AuditLog::log('users', $id, 'VIEW');

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $this->dataUser($user),
        ];
    }

    public function actionCreate(): array
    {
        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        $now = date('Y-m-d H:i:s');
        $currentUserId = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        $user = new User();
        $user->username = trim((string)($params['username'] ?? ''));
        $user->name = trim((string)($params['name'] ?? $params['full_name'] ?? ''));
        $user->email = !empty($params['email']) ? trim((string)$params['email']) : null;
        $user->roles = isset($params['roles']) ? (int)$params['roles'] : (isset($params['role_id']) ? (int)$params['role_id'] : null);
        $user->password_hash = Yii::$app->security->generatePasswordHash(md5('Admin@123'));
        $user->is_active = true;
        $user->created_at = $now;
        $user->created_by = $currentUserId;
        $user->updated_at = $now;
        $user->updated_by = $currentUserId;

        if (!$user->save()) {
            return [
                'rc' => 422,
                'rm' => 'Validasi gagal',
                'data' => $user->getErrors(),
            ];
        }

        $newUser = User::find()
            ->alias('u')
            ->select(['u.*', 'r.name AS role_name'])
            ->leftJoin('roles r', 'r.id = u.roles')
            ->where(['u.id' => $user->id])
            ->one();

        $userData = $this->dataUser($newUser ?? $user);
        AuditLog::log('users', (int)$user->id, 'CREATE', null, $userData);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $userData,
        ];
    }

    public function actionUpdate(int $id): array
    {
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return [
                'rc' => 404,
                'rm' => 'Data user tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataUser($user);

        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        if (isset($params['username'])) {
            $user->username = trim((string)$params['username']);
        }
        if (isset($params['name']) || isset($params['full_name'])) {
            $user->name = trim((string)($params['name'] ?? $params['full_name']));
        }
        if (array_key_exists('email', $params)) {
            $email = trim((string)$params['email']);
            $user->email = $email !== '' ? $email : null;
        }
        if (isset($params['roles']) || isset($params['role_id'])) {
            $roleVal = $params['roles'] ?? $params['role_id'];
            $user->roles = $roleVal !== '' && $roleVal !== null ? (int)$roleVal : null;
        }
        if (isset($params['is_active'])) {
            $user->is_active = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $user->updated_at = date('Y-m-d H:i:s');
        $user->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;

        if (!$user->save()) {
            return [
                'rc' => 422,
                'rm' => 'Validasi gagal',
                'data' => $user->getErrors(),
            ];
        }

        $updatedUser = User::find()
            ->alias('u')
            ->select(['u.*', 'r.name AS role_name'])
            ->leftJoin('roles r', 'r.id = u.roles')
            ->where(['u.id' => $user->id])
            ->one();

        $newValues = $this->dataUser($updatedUser ?? $user);
        AuditLog::log('users', (int)$user->id, 'UPDATE', $oldValues, $newValues);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => $newValues,
        ];
    }

    public function actionStatus(int $id): array
    {
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return [
                'rc' => 404,
                'rm' => 'Data user tidak ditemukan',
                'data' => null,
            ];
        }

        $oldStatus = (bool)$user->is_active;

        $request = Yii::$app->request;
        $params = array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );

        if (isset($params['is_active'])) {
            $user->is_active = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $user->is_active = !$user->is_active;
        }

        $user->updated_at = date('Y-m-d H:i:s');
        $user->updated_by = Yii::$app->user->id !== null ? (int)Yii::$app->user->id : null;
        $user->save(false);

        $statusText = $user->is_active ? 'diaktifkan' : 'dinonaktifkan';

        $updatedUser = User::find()
            ->alias('u')
            ->select(['u.*', 'r.name AS role_name'])
            ->leftJoin('roles r', 'r.id = u.roles')
            ->where(['u.id' => $user->id])
            ->one();

        $userData = $this->dataUser($updatedUser ?? $user);
        AuditLog::log('users', (int)$user->id, 'STATUS', ['is_active' => $oldStatus], ['is_active' => (bool)$user->is_active]);

        return [
            'rc' => 200,
            'rm' => "User berhasil {$statusText}",
            'data' => $userData,
        ];
    }

    public function actionDelete(int $id): array
    {
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return [
                'rc' => 404,
                'rm' => 'Data user tidak ditemukan',
                'data' => null,
            ];
        }

        $oldValues = $this->dataUser($user);

        try {
            $user->delete();
        } catch (\yii\db\IntegrityException $e) {
            return [
                'rc'   => 409,
                'rm'   => 'Data user tidak dapat dihapus karena masih terkait dengan data lain',
                'data' => null,
            ];
        }

        AuditLog::log('users', (int)$user->id, 'DELETE', $oldValues, null);

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => null,
        ];
    }

    private function dataUser(User|array $user): array
    {
        $isObject = $user instanceof User;

        return [
            'id' => (int)($isObject ? $user->id : $user['id']),
            'username' => $isObject ? $user->username : $user['username'],
            'email' => $isObject ? $user->email : $user['email'],
            'full_name' => $isObject ? $user->full_name : $user['full_name'],
            'role_id' => $isObject ? ($user->roles !== null ? (int)$user->roles : null) : (isset($user['roles']) && $user['roles'] !== null ? (int)$user['roles'] : null),
            'role_name' => $isObject ? ($user->role_name ?? $user->role?->name) : ($user['role_name'] ?? null),
            'is_active' => (bool)($isObject ? $user->is_active : $user['is_active']),
        ];
    }
}
