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

                // Poli routes (support with and without /api)
                'GET api/polis/options' => 'polis/options',
                'GET polis/options' => 'polis/options',
                'GET api/polis' => 'polis/index',
                'GET polis' => 'polis/index',
                'GET api/polis/<id:\d+>' => 'polis/view',
                'GET polis/<id:\d+>' => 'polis/view',
                'POST api/polis' => 'polis/create',
                'POST polis' => 'polis/create',
                'PUT,PATCH api/polis/<id:\d+>' => 'polis/update',
                'PUT,PATCH polis/<id:\d+>' => 'polis/update',
                'DELETE api/polis/<id:\d+>' => 'polis/delete',
                'DELETE polis/<id:\d+>' => 'polis/delete',

                // Doctor routes (support with and without /api)
                'GET api/doctors/options' => 'doctors/options',
                'GET doctors/options' => 'doctors/options',
                'GET api/doctors' => 'doctors/index',
                'GET doctors' => 'doctors/index',
                'GET api/doctors/<id:\d+>' => 'doctors/view',
                'GET doctors/<id:\d+>' => 'doctors/view',
                'POST api/doctors' => 'doctors/create',
                'POST doctors' => 'doctors/create',
                'PUT,PATCH api/doctors/<id:\d+>' => 'doctors/update',
                'PUT,PATCH doctors/<id:\d+>' => 'doctors/update',
                'DELETE api/doctors/<id:\d+>' => 'doctors/delete',
                'DELETE doctors/<id:\d+>' => 'doctors/delete',
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
