<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\filters\auth\AuthMethod;
use yii\web\Response;

class JwtAuth extends AuthMethod
{
    /**
     * @var string the HTTP header name for bearer token
     */
    public string $header = 'Authorization';

    /**
     * @var string a pattern to extract bearer token
     */
    public string $pattern = '/^Bearer\s+(.*?)$/';

    /**
     * {@inheritdoc}
     */
    public function authenticate($user, $request, $response)
    {
        $authHeader = $request->getHeaders()->get($this->header);
        $token = null;

        if ($authHeader !== null && preg_match($this->pattern, $authHeader, $matches)) {
            $token = $matches[1];
        }

        // Fallback: check custom 'token' or 'X-Access-Token' header
        if ($token === null) {
            $token = $request->getHeaders()->get('token')
                ?? $request->getHeaders()->get('X-Access-Token')
                ?? $request->get('token')
                ?? $request->get('access-token');
        }

        if ($token !== null) {
            $identity = $user->loginByAccessToken($token, get_class($this));
            if ($identity !== null) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function handleFailure($response)
    {
        $response->format = Response::FORMAT_JSON;
        $response->statusCode = 200; // Keep HTTP 200 with rc=401 for custom response contract
        $response->data = [
            'rc' => 401,
            'rm' => 'Unauthorized: Token tidak valid atau tidak disertakan',
            'data' => null,
        ];
    }
}
