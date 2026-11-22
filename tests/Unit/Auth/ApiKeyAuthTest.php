<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\ApiKeyAuth;
use App\Model\Worker;
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

    public function testGeneratedWorkerKeyReturnsWorkerRole(): void
    {
        $key = str_repeat('a', 64);

        // the database is searched by the SHA-256 of the key, never by the key itself
        $workers = $this->createMock(Worker::class);
        $workers->expects($this->once())
            ->method('findIdByApiKeyHash')
            ->with(hash('sha256', $key))
            ->willReturn(7);

        $this->assertSame('worker', (new ApiKeyAuth(['boss' => 'boss-key'], $workers))->roleFor($key));
    }

    public function testUnknownGeneratedKeyReturnsNull(): void
    {
        $workers = $this->createStub(Worker::class);
        $workers->method('findIdByApiKeyHash')->willReturn(null);

        $this->assertNull((new ApiKeyAuth(['boss' => 'boss-key'], $workers))->roleFor('unknown-key'));
    }

    public function testSendingTheStoredHashAsKeyIsRejected(): void
    {
        // a leaked hash must not work as a key: the API hashes it again, which matches nothing
        $storedHash = hash('sha256', str_repeat('a', 64));

        $workers = $this->createStub(Worker::class);
        $workers->method('findIdByApiKeyHash')
            ->willReturnCallback(fn (string $hash) => $hash === $storedHash ? 7 : null);

        $this->assertNull((new ApiKeyAuth([], $workers))->roleFor($storedHash));
    }

    public function testEnvKeyDoesNotQueryTheDatabase(): void
    {
        $workers = $this->createMock(Worker::class);
        $workers->expects($this->never())->method('findIdByApiKeyHash');

        $this->assertSame('boss', (new ApiKeyAuth(['boss' => 'boss-key'], $workers))->roleFor('boss-key'));
    }

    public function testEmptyKeyDoesNotQueryTheDatabase(): void
    {
        $workers = $this->createMock(Worker::class);
        $workers->expects($this->never())->method('findIdByApiKeyHash');

        $auth = new ApiKeyAuth([], $workers);

        $this->assertNull($auth->roleFor(null));
        $this->assertNull($auth->roleFor(''));
    }
}
