<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\ApiKeyAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiKeyAuthTest extends TestCase
{
    private function auth(): ApiKeyAuth
    {
        return new ApiKeyAuth(['boss' => 'boss-key', 'worker' => 'worker-key']);
    }

    public function testBossKeyReturnsBossRole(): void
    {
        $this->assertSame('boss', $this->auth()->roleFor('boss-key'));
    }

    public function testWorkerKeyReturnsWorkerRole(): void
    {
        $this->assertSame('worker', $this->auth()->roleFor('worker-key'));
    }

    public static function invalidKeys(): array
    {
        return [
            'sem header'        => [null],
            'header vazio'      => [''],
            'chave errada'      => ['outra-chave'],
            'prefixo da chave'  => ['boss'],
            'chave com espaço'  => [' boss-key'],
            'maiúsculas'        => ['BOSS-KEY'],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeyReturnsNull(?string $key): void
    {
        $this->assertNull($this->auth()->roleFor($key));
    }

    public function testKeyNotConfiguredDoesNotAuthorizeEmptyHeader(): void
    {
        // Se API_SECRET_KEY_WORKER não estiver no .env, um header vazio não pode virar "worker"
        $auth = new ApiKeyAuth(['boss' => 'boss-key', 'worker' => null]);

        $this->assertNull($auth->roleFor(''));
        $this->assertNull($auth->roleFor(null));
    }

    public function testEmptyConfiguredKeyIsIgnored(): void
    {
        // API_SECRET_KEY_WORKER= (vazia) no .env não pode liberar acesso
        $auth = new ApiKeyAuth(['boss' => 'boss-key', 'worker' => '']);

        $this->assertNull($auth->roleFor(''));
        $this->assertSame('boss', $auth->roleFor('boss-key'));
    }

    public function testNoKeysConfiguredDeniesEverything(): void
    {
        $this->assertNull((new ApiKeyAuth([]))->roleFor('qualquer'));
    }
}
