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
                'POST api/login' => 'site/login',
                'login' => 'site/login',
                'GET users' => 'users/index',
                'GET api/users' => 'users/index',
                'GET users/<id:\d+>' => 'users/view',
                'GET api/users/<id:\d+>' => 'users/view',
                'POST users' => 'users/create',
                'POST api/users' => 'users/create',
                'PUT,PATCH,POST users/<id:\d+>/status' => 'users/status',
                'PUT,PATCH,POST api/users/<id:\d+>/status' => 'users/status',
                'PUT,PATCH users/<id:\d+>' => 'users/update',
                'PUT,PATCH api/users/<id:\d+>' => 'users/update',
                'DELETE users/<id:\d+>' => 'users/delete',
                'DELETE api/users/<id:\d+>' => 'users/delete',

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

                'GET api/schedules/available' => 'schedules/available',
                'GET schedules/available' => 'schedules/available',
                'GET api/schedules' => 'schedules/index',
                'GET schedules' => 'schedules/index',
                'GET api/schedules/<id:\d+>' => 'schedules/view',
                'GET schedules/<id:\d+>' => 'schedules/view',
                'POST api/schedules' => 'schedules/create',
                'POST schedules' => 'schedules/create',
                'PUT,PATCH api/schedules/<id:\d+>' => 'schedules/update',
                'PUT,PATCH schedules/<id:\d+>' => 'schedules/update',
                'DELETE api/schedules/<id:\d+>' => 'schedules/delete',
                'DELETE schedules/<id:\d+>' => 'schedules/delete',

                'POST api/patients/check-duplicate' => 'patients/check-duplicate',
                'POST patients/check-duplicate' => 'patients/check-duplicate',
                'GET api/patients/<id:\d+>/visits' => 'patients/visits',
                'GET patients/<id:\d+>/visits' => 'patients/visits',
                'GET api/patients/<id:\d+>/medical-records' => 'patients/medical-records',
                'GET patients/<id:\d+>/medical-records' => 'patients/medical-records',
                'GET api/patients' => 'patients/index',
                'GET patients' => 'patients/index',
                'GET api/patients/<id:\d+>' => 'patients/view',
                'GET patients/<id:\d+>' => 'patients/view',
                'POST api/patients' => 'patients/create',
                'POST patients' => 'patients/create',
                'PUT,PATCH api/patients/<id:\d+>' => 'patients/update',
                'PUT,PATCH patients/<id:\d+>' => 'patients/update',
                'DELETE api/patients/<id:\d+>' => 'patients/delete',
                'DELETE patients/<id:\d+>' => 'patients/delete',

                'GET api/procedures/options' => 'procedures/options',
                'GET procedures/options' => 'procedures/options',
                'GET api/procedures' => 'procedures/index',
                'GET procedures' => 'procedures/index',
                'GET api/procedures/<id:\d+>' => 'procedures/view',
                'GET procedures/<id:\d+>' => 'procedures/view',
                'POST api/procedures' => 'procedures/create',
                'POST procedures' => 'procedures/create',
                'PUT,PATCH api/procedures/<id:\d+>' => 'procedures/update',
                'PUT,PATCH procedures/<id:\d+>' => 'procedures/update',
                'DELETE api/procedures/<id:\d+>' => 'procedures/delete',
                'DELETE procedures/<id:\d+>' => 'procedures/delete',

                'GET api/drugs/options' => 'drugs/options',
                'GET drugs/options' => 'drugs/options',
                'POST api/drugs/<id:\d+>/stock-adjustments' => 'drugs/stock-adjustments',
                'POST drugs/<id:\d+>/stock-adjustments' => 'drugs/stock-adjustments',
                'GET api/drugs/<id:\d+>/stock-movements' => 'drugs/stock-movements',
                'GET drugs/<id:\d+>/stock-movements' => 'drugs/stock-movements',
                'GET api/drugs' => 'drugs/index',
                'GET drugs' => 'drugs/index',
                'GET api/drugs/<id:\d+>' => 'drugs/view',
                'GET drugs/<id:\d+>' => 'drugs/view',
                'POST api/drugs' => 'drugs/create',
                'POST drugs' => 'drugs/create',
                'PUT,PATCH api/drugs/<id:\d+>' => 'drugs/update',
                'PUT,PATCH drugs/<id:\d+>' => 'drugs/update',
                'DELETE api/drugs/<id:\d+>' => 'drugs/delete',
                'DELETE drugs/<id:\d+>' => 'drugs/delete',

                'GET api/lab-tests/options' => 'lab-tests/options',
                'GET lab-tests/options' => 'lab-tests/options',
                'GET api/lab-tests' => 'lab-tests/index',
                'GET lab-tests' => 'lab-tests/index',
                'GET api/lab-tests/<id:\d+>' => 'lab-tests/view',
                'GET lab-tests/<id:\d+>' => 'lab-tests/view',
                'POST api/lab-tests' => 'lab-tests/create',
                'POST lab-tests' => 'lab-tests/create',
                'PUT,PATCH api/lab-tests/<id:\d+>' => 'lab-tests/update',
                'PUT,PATCH lab-tests/<id:\d+>' => 'lab-tests/update',
                'DELETE api/lab-tests/<id:\d+>' => 'lab-tests/delete',
                'DELETE lab-tests/<id:\d+>' => 'lab-tests/delete',

                'GET api/icd10/options' => 'icd10/options',
                'GET icd10/options' => 'icd10/options',
                'GET api/icd10' => 'icd10/index',
                'GET icd10' => 'icd10/index',
                'POST api/icd10' => 'icd10/create',
                'POST icd10' => 'icd10/create',
                'GET api/icd10/<code:[A-Za-z0-9._-]+>' => 'icd10/view',
                'GET icd10/<code:[A-Za-z0-9._-]+>' => 'icd10/view',
                'PUT,PATCH api/icd10/<code:[A-Za-z0-9._-]+>' => 'icd10/update',
                'PUT,PATCH icd10/<code:[A-Za-z0-9._-]+>' => 'icd10/update',
                'DELETE api/icd10/<code:[A-Za-z0-9._-]+>' => 'icd10/delete',
                'DELETE icd10/<code:[A-Za-z0-9._-]+>' => 'icd10/delete',

                'GET api/visits' => 'visits/index',
                'GET visits' => 'visits/index',
                'POST api/visits' => 'visits/create',
                'POST visits' => 'visits/create',
                'GET api/visits/<id:\d+>' => 'visits/view',
                'GET visits/<id:\d+>' => 'visits/view',
                'PUT,PATCH api/visits/<id:\d+>/status' => 'visits/status',
                'PUT,PATCH visits/<id:\d+>/status' => 'visits/status',
                'POST api/visits/<id:\d+>/examination/start' => 'examinations/start',
                'POST visits/<id:\d+>/examination/start' => 'examinations/start',
                'POST api/visits/<id:\d+>/finish' => 'visits/finish',
                'POST visits/<id:\d+>/finish' => 'visits/finish',
                'GET api/visits/<id:\d+>/procedures' => 'visit-procedures/index',
                'GET visits/<id:\d+>/procedures' => 'visit-procedures/index',
                'POST api/visits/<id:\d+>/procedures' => 'visit-procedures/create',
                'POST visits/<id:\d+>/procedures' => 'visit-procedures/create',
                'POST api/visits/<id:\d+>/procedures/<vpId:\d+>/void' => 'visit-procedures/void',
                'POST visits/<id:\d+>/procedures/<vpId:\d+>/void' => 'visit-procedures/void',

                'GET api/queues' => 'queues/index',
                'GET queues' => 'queues/index',

                'GET api/examinations/waiting' => 'examinations/waiting',
                'GET examinations/waiting' => 'examinations/waiting',
                'GET api/examinations/<id:\d+>' => 'examinations/view',
                'GET examinations/<id:\d+>' => 'examinations/view',
                'PUT,PATCH api/examinations/<id:\d+>' => 'examinations/update',
                'PUT,PATCH examinations/<id:\d+>' => 'examinations/update',
                'POST api/examinations/<id:\d+>/finalize' => 'examinations/finalize',
                'POST examinations/<id:\d+>/finalize' => 'examinations/finalize',
                'POST api/examinations/<id:\d+>/revise' => 'examinations/revise',
                'POST examinations/<id:\d+>/revise' => 'examinations/revise',
                'GET api/examinations/<id:\d+>/revisions' => 'examinations/revisions',
                'GET examinations/<id:\d+>/revisions' => 'examinations/revisions',
                'GET api/examinations/<id:\d+>/revisions/<version:\d+>' => 'examinations/revision-detail',
                'GET examinations/<id:\d+>/revisions/<version:\d+>' => 'examinations/revision-detail',

                // Billing routes
                'GET api/billing/queue' => 'billing/queue',
                'GET billing/queue' => 'billing/queue',
                'POST api/visits/<id:\d+>/bill' => 'bills/create-for-visit',
                'POST visits/<id:\d+>/bill' => 'bills/create-for-visit',
                'GET api/visits/<id:\d+>/bill' => 'bills/view-by-visit',
                'GET visits/<id:\d+>/bill' => 'bills/view-by-visit',
                'GET api/bills' => 'bills/index',
                'GET bills' => 'bills/index',
                'GET api/bills/<id:\d+>' => 'bills/view',
                'GET bills/<id:\d+>' => 'bills/view',
                'POST api/bills/<id:\d+>/issue' => 'bills/issue',
                'POST bills/<id:\d+>/issue' => 'bills/issue',
                'POST api/bills/<id:\d+>/cancel' => 'bills/cancel',
                'POST bills/<id:\d+>/cancel' => 'bills/cancel',
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
