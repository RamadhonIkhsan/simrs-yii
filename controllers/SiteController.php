<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\models\ContactForm;
use app\models\LoginForm;
use yii\captcha\CaptchaAction;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\base\Security;
use yii\mail\MailerInterface;
use yii\web\Controller;
use yii\web\ErrorAction;
use yii\web\Response;

class SiteController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly MailerInterface $mailer,
        private readonly Security $security,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['logout'],
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions(): array
    {
        return [
            'error' => [
                'class' => ErrorAction::class,
            ],
            'captcha' => [
                'class' => CaptchaAction::class,
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
                'transparent' => true,
            ],
        ];
    }

    /**
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex(): string
    {
        return $this->render('index');
    }

    /**
     * {@inheritdoc}
     */
    public function beforeAction($action): bool
    {
        if ($action->id === 'login') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Login action.
     *
     * @return Response|array
     */
    public function actionLogin(): Response|array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $request = Yii::$app->request;
        if (!$request->isPost) {
            return [
                'rc' => 405,
                'rm' => 'Method tidak diizinkan. Gunakan method POST.',
                'data' => null,
            ];
        }

        $body = (array)$request->getBodyParams();
        $post = (array)$request->post();

        $username = trim((string)($post['username'] ?? $body['username'] ?? ''));
        $password = (string)($post['password'] ?? $body['password'] ?? '');

        if ($username === '' || $password === '') {
            return [
                'rc' => 400,
                'rm' => 'Username dan password wajib diisi',
                'data' => null,
            ];
        }

        $user = \app\models\User::findByUsername($username);
        if (!$user || !$user->validatePassword($password)) {
            return [
                'rc' => 401,
                'rm' => 'Username atau password salah',
                'data' => null,
            ];
        }

        if (!$user->is_active) {
            return [
                'rc' => 403,
                'rm' => 'User tidak aktif',
                'data' => null,
            ];
        }


        $token = \app\components\JwtHelper::generateToken([
            'uid' => (int)$user->id,
            'username' => $user->username,
            'role_id' => $user->role_id,
            'role_name' => $user->role_name,
        ]);

        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save(false);

        if (!headers_sent()) {
            Yii::$app->user->login($user, 3600 * 24 * 30);
        } else {
            Yii::$app->user->setIdentity($user);
        }

        return [
            'rc' => 200,
            'rm' => 'Berhasil',
            'data' => [
                'user' => [
                    'id' => (int)$user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'full_name' => $user->full_name,
                    'is_active' => (bool)$user->is_active,
                    'last_login_at' => $user->last_login_at,
                    'created_at' => $user->created_at,
                    'created_by' => $user->created_by !== null ? (int)$user->created_by : null,
                ],
                'token' => $token,
            ],
        ];
    }

    /**
     * Logout action.
     *
     * @return Response
     */
    public function actionLogout(): Response
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }

    /**
     * Displays contact page.
     *
     * @return Response|string
     */
    public function actionContact(): Response|string
    {
        $model = new ContactForm();

        $contact = $model->load($this->request->post()) && $model->contact(
            $this->mailer,
            Yii::$app->params['adminEmail'],
            Yii::$app->params['senderEmail'],
            Yii::$app->params['senderName'],
        );

        if ($contact) {
            Yii::$app->session->setFlash(
                'success',
                'Thank you for contacting us. We will respond to you as soon as possible.',
            );

            return $this->refresh();
        }

        return $this->render('contact', ['model' => $model]);
    }

    /**
     * Displays about page.
     *
     * @return string
     */
    public function actionAbout(): string
    {
        return $this->render('about');
    }
}
