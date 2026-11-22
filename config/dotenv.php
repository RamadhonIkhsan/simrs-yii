<?php

declare(strict_types=1);

if (!function_exists('dd')) {
    function dd(...$vars): void
    {
        foreach ($vars as $v) {
            \yii\helpers\VarDumper::dump($v, 10, true);
            echo "\n";
        }
        exit(1);
    }
}

if (!function_exists('loadEnv')) {
    function loadEnv(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $val = getenv($key);
        if ($val === false || $val === null) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }

        if ($val === 'true' || $val === '(true)') {
            return true;
        }
        if ($val === 'false' || $val === '(false)') {
            return false;
        }
        if ($val === 'null' || $val === '(null)') {
            return null;
        }

        return $val;
    }
}

loadEnv(dirname(__DIR__) . '/.env');
date_default_timezone_set((string)env('APP_TIMEZONE', 'Asia/Jakarta'));
