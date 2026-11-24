<?php

declare(strict_types=1);

namespace app\components;

use Yii;

class JwtHelper
{
    
    private static function getSecret(): string
    {
        $secret = env('JWT_SECRET');
        if (!empty($secret)) {
            return (string)$secret;
        }

        if (!empty(Yii::$app->params['jwtSecret'])) {
            return (string)Yii::$app->params['jwtSecret'];
        }

        if (!empty(Yii::$app->request->cookieValidationKey)) {
            return (string)Yii::$app->request->cookieValidationKey;
        }

        return 'simrs-yii-jwt-secret-key-2026';
    }
    
    public static function generateToken(array $payload, int $ttl = 604800): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $now = time();
        $claims = array_merge([
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ], $payload);

        $base64Header = self::base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES));
        $base64Payload = self::base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

        $signature = hash_hmac('sha256', "{$base64Header}.{$base64Payload}", self::getSecret(), true);
        $base64Signature = self::base64UrlEncode($signature);

        return "{$base64Header}.{$base64Payload}.{$base64Signature}";
    }

    /**
     * Validate and decode JWT token.
     *
     * @param string $token
     * @return array|null Decoded payload or null if invalid/expired
     */
    public static function validateToken(string $token): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return null;
        }

        [$base64Header, $base64Payload, $base64Signature] = $parts;

        $signature = self::base64UrlDecode($base64Signature);
        $expectedSignature = hash_hmac('sha256', "{$base64Header}.{$base64Payload}", self::getSecret(), true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($base64Payload);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (isset($payload['exp']) && $payload['exp'] < $now) {
            return null;
        }

        if (isset($payload['nbf']) && $payload['nbf'] > $now) {
            return null;
        }

        return $payload;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }

        return (string)base64_decode(strtr($data, '-_', '+/'));
    }
}
