<?php

declare(strict_types=1);

require_once __DIR__ . '/dotenv.php';

$host = env('DB_HOST', '127.0.0.1');
$port = env('DB_PORT', '5432');
$dbname = env('DB_DATABASE', 'simrs');
$username = env('DB_USERNAME', 'postgres');
$password = env('DB_PASSWORD', '');

return [
    'class' => \yii\db\Connection::class,
    'dsn' => "pgsql:host={$host};port={$port};dbname={$dbname}",
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8',
    'on afterOpen' => function ($event) {
        $tz = env('APP_TIMEZONE', 'Asia/Jakarta');
        $event->sender->createCommand("SET TIME ZONE '{$tz}'")->execute();
    },

    // Schema cache options (for production environment)
    //'enableSchemaCache' => true,
    //'schemaCacheDuration' => 60,
    //'schemaCache' => 'cache',
];
