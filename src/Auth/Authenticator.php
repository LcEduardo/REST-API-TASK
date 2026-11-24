<?php

namespace App\Auth;

// Decides who is calling from the request headers: a JWT in "Authorization: Bearer"
// or an API key in X-API-Key.
final class Authenticator
{
    public const METHOD_JWT = 'jwt';
    public const METHOD_API_KEY = 'api_key';

    // $jwt is null when JWT_SECRET is not configured; Bearer tokens are then rejected
    public function __construct(private ApiKeyAuth $apiKeys, private ?JwtAuth $jwt = null)
    {
    }

    /**
     * Returns ['role' => ..., 'method' => METHOD_JWT|METHOD_API_KEY], or null if not authenticated.
     * May throw PDOException (the API key lookup hits the database).
     */
    public function authenticate(?string $authorization, ?string $apiKey): ?array
    {
        // An Authorization header must hold a valid token. We do not fall back to the API key,
        // so an expired or forged token is always reported as a failure.
        if ($authorization !== null && $authorization !== '') {
            $token = self::bearerToken($authorization);
            $role = ($token === null || $this->jwt === null) ? null : $this->jwt->roleFor($token);

            return $role === null ? null : ['role' => $role, 'method' => self::METHOD_JWT];
        }

        $role = $this->apiKeys->roleFor($apiKey);

        return $role === null ? null : ['role' => $role, 'method' => self::METHOD_API_KEY];
    }

    // "Bearer <token>" -> "<token>". The scheme is case-insensitive (RFC 7235); anything else -> null.
    public static function bearerToken(string $authorization): ?string
    {
        if (preg_match('/^Bearer +(\S+)\s*$/i', $authorization, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
