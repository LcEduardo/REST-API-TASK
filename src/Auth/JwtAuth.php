<?php

namespace App\Auth;

use Closure;
use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use UnexpectedValueException;

// Issues and verifies the JWTs sent as "Authorization: Bearer <token>".
// Tokens are signed with HS256 using JWT_SECRET and carry the caller's role.
final class JwtAuth
{
    // The only algorithm accepted. Pinning it blocks "alg: none" and algorithm-confusion attacks.
    private const ALGORITHM = 'HS256';

    // HS256 needs a key at least as long as its output (256 bits)
    private const MIN_SECRET_BYTES = 32;

    private const ROLES = ['boss', 'worker'];

    private Closure $clock;

    /**
     * @param string $secret HMAC key (JWT_SECRET)
     * @param int $ttl token lifetime in seconds
     * @param ?Closure $clock returns the current Unix time; injectable for tests
     */
    public function __construct(private string $secret, private int $ttl = 3600, ?Closure $clock = null)
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException('JWT_SECRET must be at least ' . self::MIN_SECRET_BYTES . ' bytes long');
        }

        if ($ttl <= 0) {
            throw new InvalidArgumentException('The JWT lifetime must be positive');
        }

        $this->clock = $clock ?? fn (): int => time();
    }

    // Signs a token for $role. Returns the body of the POST /token response.
    public function issue(string $role): array
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Unknown role: $role");
        }

        $now = ($this->clock)();
        $payload = [
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + $this->ttl,
            // Unique id, so two tokens issued in the same second are still different
            'jti'  => bin2hex(random_bytes(16)),
        ];

        return [
            'token'      => JWT::encode($payload, $this->secret, self::ALGORITHM),
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl,
        ];
    }

    // Returns the role inside a valid token, or null if the token is malformed, forged, expired or has no valid role
    public function roleFor(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        // JWT::decode reads the clock from a static property; set it and always restore it
        $previous = JWT::$timestamp;
        JWT::$timestamp = ($this->clock)();

        try {
            $claims = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (UnexpectedValueException | DomainException | InvalidArgumentException) {
            // Covers bad signature, expired, not yet valid, wrong algorithm and malformed tokens
            return null;
        } finally {
            JWT::$timestamp = $previous;
        }

        // A token without expiry would be valid forever
        if (!isset($claims->exp)) {
            return null;
        }

        $role = $claims->role ?? null;

        return is_string($role) && in_array($role, self::ROLES, true) ? $role : null;
    }
}
