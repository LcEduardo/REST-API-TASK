<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Auth\Authenticator;
use App\Auth\JwtAuth;
use App\Controller\ControllerAuth;
use PHPUnit\Framework\TestCase;

final class ControllerAuthTest extends TestCase
{
    private function jwt(): JwtAuth
    {
        return new JwtAuth('test-secret-that-is-at-least-32-bytes-long', 600);
    }

    public function testApiKeyIsExchangedForAToken(): void
    {
        $jwt = $this->jwt();
        $response = (new ControllerAuth($jwt))->createToken('worker', Authenticator::METHOD_API_KEY);

        $this->assertSame(200, $response['status']);
        $this->assertSame('Bearer', $response['body']['token_type']);
        $this->assertSame(600, $response['body']['expires_in']);
        $this->assertSame('worker', $jwt->roleFor($response['body']['token']));
    }

    public function testTokenCannotRenewItself(): void
    {
        // Otherwise a leaked token could be refreshed forever
        $response = (new ControllerAuth($this->jwt()))->createToken('boss', Authenticator::METHOD_JWT);

        $this->assertSame(403, $response['status']);
        $this->assertSame(['error' => 'A token can only be issued with an API key'], $response['body']);
    }

    public function testUnauthenticatedCallerGets403(): void
    {
        $response = (new ControllerAuth($this->jwt()))->createToken(null, null);

        $this->assertSame(403, $response['status']);
    }

    public function testRespondsWith500WhenJwtIsNotConfigured(): void
    {
        $response = (new ControllerAuth(null))->createToken('boss', Authenticator::METHOD_API_KEY);

        $this->assertSame(500, $response['status']);
        $this->assertSame(['error' => 'JWT authentication is not configured'], $response['body']);
    }
}
