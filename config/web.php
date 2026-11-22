<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

if (!is_dir(__DIR__ . '/../runtime/sessions')) {
    @mkdir(__DIR__ . '/../runtime/sessions', 0777, true);
}

$config = [
    'id' => 'basic',
    'basePath' => dirname(__DIR__),
    'timeZone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
    'bootstrap' => ['log'],
    'container' => [
        'singletons' => [
            \yii\mail\MailerInterface::class => [
                'class' => \yii\symfonymailer\Mailer::class,
                // send all mails to a file by default.
                'useFileTransport' => true,
                'viewPath' => '@app/mail',
            ],
        ],
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            // !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
            'cookieValidationKey' => 'BkrrO4qLRMRF-VqRW20OI0JhBphbF8Z8',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
        'session' => [
            'savePath' => '@runtime/sessions',
        ],
        'user' => [
            'identityClass' => \app\models\User::class,
            'enableAutoLogin' => true,
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'mailer' => \yii\mail\MailerInterface::class,
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'POST login' => 'site/login',
                'login' => 'site/login',
                'GET users' => 'users/index',
                'GET users/<id:\d+>' => 'users/view',
                'POST users' => 'users/create',
                'PUT,PATCH,POST users/<id:\d+>/status' => 'users/status',
                'PUT,PATCH users/<id:\d+>' => 'users/update',
                'DELETE users/<id:\d+>' => 'users/delete',
            ],
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => \yii\debug\Module::class,
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];

    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => \yii\gii\Module::class,
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];
}

return $config;
