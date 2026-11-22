<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Model\Worker;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    public function testGetByIdDoesNotExposeApiKeyHash(): void
    {
        // the SELECT * brings every column, including the hash; getById must drop it
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id'           => 1,
            'name'         => 'Ana',
            'email'        => 'ana@example.com',
            'created'      => '2026-11-21 10:00:00',
            'api_key_hash' => hash('sha256', 'secret'),
        ]);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $row = (new Worker($pdo))->getById(1);

        $this->assertArrayNotHasKey('api_key_hash', $row);
        $this->assertSame(
            ['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com', 'created' => '2026-11-21 10:00:00'],
            $row
        );
    }

    public function testFindIdByApiKeyHashReturnsTheWorkerId(): void
    {
        $hash = hash('sha256', 'secret');

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['hash' => $hash])->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('7'); // PDO returns columns as strings

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $this->assertSame(7, (new Worker($pdo))->findIdByApiKeyHash($hash));
    }

    public function testFindIdByApiKeyHashReturnsNullWhenNotFound(): void
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(false);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $this->assertNull((new Worker($pdo))->findIdByApiKeyHash(hash('sha256', 'nope')));
    }
}
