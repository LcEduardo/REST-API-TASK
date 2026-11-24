<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\JwtAuth;
use Firebase\JWT\JWT;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwtAuthTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';
    private const NOW = 1_800_000_000;

    private function jwt(int $now = self::NOW, int $ttl = 3600): JwtAuth
    {
        return new JwtAuth(self::SECRET, $ttl, fn (): int => $now);
    }

    // Base64url without padding, as used by JWT
    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function testIssuedTokenReturnsItsRole(): void
    {
        $jwt = $this->jwt();

        $this->assertSame('boss', $jwt->roleFor($jwt->issue('boss')['token']));
        $this->assertSame('worker', $jwt->roleFor($jwt->issue('worker')['token']));
    }

    public function testIssueReturnsBearerTokenWithLifetime(): void
    {
        $body = $this->jwt(ttl: 900)->issue('worker');

        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame(900, $body['expires_in']);
        $this->assertCount(3, explode('.', $body['token']));
    }

    public function testTokensIssuedInTheSameSecondAreDifferent(): void
    {
        $jwt = $this->jwt();

        $this->assertNotSame($jwt->issue('boss')['token'], $jwt->issue('boss')['token']);
    }

    public function testTokenIsValidUntilItExpires(): void
    {
        $token = $this->jwt()->issue('boss')['token'];

        $this->assertSame('boss', $this->jwt(self::NOW + 3599)->roleFor($token));
        $this->assertNull($this->jwt(self::NOW + 3600)->roleFor($token));
    }

    public function testTokenSignedWithAnotherSecretIsRejected(): void
    {
        $forged = JWT::encode(
            ['role' => 'boss', 'iat' => self::NOW, 'exp' => self::NOW + 60],
            'another-secret-that-is-also-32-bytes-long!',
            'HS256'
        );

        $this->assertNull($this->jwt()->roleFor($forged));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        // A worker rewrites its own token to claim the boss role, keeping the original signature
        [$header, , $signature] = explode('.', $this->jwt()->issue('worker')['token']);
        $payload = self::b64(json_encode(['role' => 'boss', 'iat' => self::NOW, 'exp' => self::NOW + 3600]));

        $this->assertNull($this->jwt()->roleFor("$header.$payload.$signature"));
    }

    public function testUnsignedTokenIsRejected(): void
    {
        // Classic attack: "alg": "none" and an empty signature
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = self::b64(json_encode(['role' => 'boss', 'iat' => self::NOW, 'exp' => self::NOW + 3600]));

        $this->assertNull($this->jwt()->roleFor("$header.$payload."));
    }

    public function testTokenWithAnotherAlgorithmIsRejected(): void
    {
        // Same secret, but HS512: only HS256 is accepted (HS512 needs a 64-byte key to sign)
        $secret = str_repeat('k', 64);
        $token = JWT::encode(['role' => 'boss', 'iat' => self::NOW, 'exp' => self::NOW + 60], $secret, 'HS512');

        $this->assertNull((new JwtAuth($secret, 3600, fn (): int => self::NOW))->roleFor($token));
    }

    public function testTokenWithoutExpiryIsRejected(): void
    {
        // Correctly signed, but it would never expire
        $token = JWT::encode(['role' => 'boss', 'iat' => self::NOW], self::SECRET, 'HS256');

        $this->assertNull($this->jwt()->roleFor($token));
    }

    public static function invalidRoleClaims(): array
    {
        return [
            'no role'      => [[]],
            'unknown role' => [['role' => 'admin']],
            'role array'   => [['role' => ['boss']]],
            'role number'  => [['role' => 1]],
        ];
    }

    #[DataProvider('invalidRoleClaims')]
    public function testTokenWithoutAValidRoleIsRejected(array $claims): void
    {
        $token = JWT::encode($claims + ['iat' => self::NOW, 'exp' => self::NOW + 60], self::SECRET, 'HS256');

        $this->assertNull($this->jwt()->roleFor($token));
    }

    public static function malformedTokens(): array
    {
        return [
            'null'          => [null],
            'empty'         => [''],
            'garbage'       => ['not-a-jwt'],
            'two segments'  => ['a.b'],
            'bad base64'    => ['!!!.@@@.###'],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenIsRejected(?string $token): void
    {
        $this->assertNull($this->jwt()->roleFor($token));
    }

    public function testVerifyingRestoresTheLibraryClock(): void
    {
        // JwtAuth sets JWT::$timestamp to its own clock; it must not leak into other code
        $this->jwt()->roleFor($this->jwt()->issue('boss')['token']);

        $this->assertNull(JWT::$timestamp);
    }

    public function testShortSecretIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JwtAuth(str_repeat('a', 31));
    }

    public function testNonPositiveLifetimeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JwtAuth(self::SECRET, 0);
    }

    public function testIssuingForUnknownRoleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->jwt()->issue('admin');
    }
}
