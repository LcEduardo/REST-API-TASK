<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\ApiKeyAuth;
use App\Auth\Authenticator;
use App\Auth\JwtAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private function jwt(): JwtAuth
    {
        return new JwtAuth(self::SECRET);
    }

    private function authenticator(?JwtAuth $jwt = null): Authenticator
    {
        return new Authenticator(new ApiKeyAuth(['boss' => 'boss-key', 'worker' => 'worker-key']), $jwt ?? $this->jwt());
    }

    public function testValidBearerTokenAuthenticates(): void
    {
        $token = $this->jwt()->issue('worker')['token'];

        $this->assertSame(
            ['role' => 'worker', 'method' => Authenticator::METHOD_JWT],
            $this->authenticator()->authenticate("Bearer $token", null)
        );
    }

    public function testApiKeyAuthenticatesWhenNoAuthorizationHeader(): void
    {
        $this->assertSame(
            ['role' => 'boss', 'method' => Authenticator::METHOD_API_KEY],
            $this->authenticator()->authenticate(null, 'boss-key')
        );
        $this->assertSame(
            ['role' => 'boss', 'method' => Authenticator::METHOD_API_KEY],
            $this->authenticator()->authenticate('', 'boss-key')
        );
    }

    public function testInvalidTokenDoesNotFallBackToApiKey(): void
    {
        // A bad token next to a good key is still a failure: the header the client chose must be valid
        $this->assertNull($this->authenticator()->authenticate('Bearer forged.token.here', 'boss-key'));
    }

    public function testBearerTokenIsRejectedWhenJwtIsNotConfigured(): void
    {
        $token = $this->jwt()->issue('boss')['token'];
        $authenticator = new Authenticator(new ApiKeyAuth(['boss' => 'boss-key']));

        $this->assertNull($authenticator->authenticate("Bearer $token", null));
    }

    public function testNoCredentialsReturnsNull(): void
    {
        $this->assertNull($this->authenticator()->authenticate(null, null));
    }

    public function testApiKeyInAuthorizationHeaderIsNotAccepted(): void
    {
        // The API key only goes in X-API-Key; it is not a bearer token
        $this->assertNull($this->authenticator()->authenticate('Bearer boss-key', null));
    }

    public static function bearerHeaders(): array
    {
        return [
            'standard'          => ['Bearer abc.def.ghi', 'abc.def.ghi'],
            'lowercase scheme'  => ['bearer abc.def.ghi', 'abc.def.ghi'],
            'extra spaces'      => ['Bearer   abc.def.ghi ', 'abc.def.ghi'],
            'other scheme'      => ['Basic dXNlcjpwYXNz', null],
            'no token'          => ['Bearer', null],
            'no token, space'   => ['Bearer ', null],
            'two tokens'        => ['Bearer a.b.c d.e.f', null],
            'scheme not first'  => ['xBearer abc.def.ghi', null],
        ];
    }

    #[DataProvider('bearerHeaders')]
    public function testBearerToken(string $header, ?string $expected): void
    {
        $this->assertSame($expected, Authenticator::bearerToken($header));
    }
}
