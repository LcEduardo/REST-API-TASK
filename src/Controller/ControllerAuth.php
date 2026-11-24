<?php

namespace App\Controller;

use App\Auth\Authenticator;
use App\Auth\JwtAuth;

class ControllerAuth
{
    // $jwt is null when JWT_SECRET is not configured
    public function __construct(private ?JwtAuth $jwt)
    {
    }

    // POST /token: exchanges an API key for a JWT.
    // Only an API key can do it: if a token could renew itself, a leaked token would never expire
    // and revoking the API key would not cut off access.
    public function createToken(?string $role, ?string $method): array
    {
        if ($method !== Authenticator::METHOD_API_KEY || $role === null) {
            return ['status' => 403, 'body' => ['error' => 'A token can only be issued with an API key']];
        }

        if ($this->jwt === null) {
            return ['status' => 500, 'body' => ['error' => 'JWT authentication is not configured']];
        }

        return ['status' => 200, 'body' => $this->jwt->issue($role)];
    }
}
