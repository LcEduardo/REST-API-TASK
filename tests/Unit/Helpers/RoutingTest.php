<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoutingTest extends TestCase
{
    public static function pathProvider(): array
    {
        return [
            'root with id'        => ['/index.php', '/tasks/1', ['tasks', '1']],
            'root without id'     => ['/index.php', '/tasks', ['tasks']],
            'subfolder'           => ['/api/index.php', '/api/task/3', ['task', '3']],
            'query string'        => ['/index.php', '/tasks?page=2&per_page=5', ['tasks']],
            'trailing slash'      => ['/index.php', '/task/3/', ['task', '3']],
            'empty path'          => ['/index.php', '/', ['']],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testParseSegments(string $scriptName, string $uri, array $expected): void
    {
        $this->assertSame($expected, parseSegments($scriptName, $uri));
    }

    // Fake route table: each handler echoes what it received
    private function routes(): array
    {
        return [
            'task' => [
                'GET'  => fn($id) => ['status' => 200, 'body' => ['id' => $id]],
                'POST' => fn() => ['status' => 201, 'body' => []],
            ],
        ];
    }

    public function testAuthFailureRespondsWith500(): void
    {
        $response = dispatch($this->routes(), 'GET', ['task', '1'], null, true);

        $this->assertSame(500, $response['status']);
        $this->assertSame(['error' => 'Database error'], $response['body']);
    }

    public function testMissingRoleRespondsWith401EvenForUnknownRoute(): void
    {
        $response = dispatch($this->routes(), 'GET', ['nope'], null, false);

        $this->assertSame(401, $response['status']);
        $this->assertSame(['error' => 'Invalid or missing API key'], $response['body']);
    }

    public function testUnknownResourceRespondsWith404(): void
    {
        $response = dispatch($this->routes(), 'GET', ['nope'], 'boss', false);

        $this->assertSame(404, $response['status']);
        $this->assertSame(['error' => 'Route not found'], $response['body']);
    }

    public function testUnsupportedMethodRespondsWith405AndAllowHeader(): void
    {
        $response = dispatch($this->routes(), 'DELETE', ['task', '1'], 'boss', false);

        $this->assertSame(405, $response['status']);
        $this->assertSame(['error' => 'Method not allowed'], $response['body']);
        $this->assertSame(['Allow' => 'GET, POST'], $response['headers']);
    }

    public function testMatchingRouteCallsHandlerWithId(): void
    {
        $response = dispatch($this->routes(), 'GET', ['task', '7'], 'worker', false);

        $this->assertSame(['status' => 200, 'body' => ['id' => '7']], $response);
    }

    public function testMatchingRouteCallsHandlerWithNullWhenNoId(): void
    {
        $response = dispatch($this->routes(), 'GET', ['task'], 'worker', false);

        $this->assertSame(['status' => 200, 'body' => ['id' => null]], $response);
    }
}
