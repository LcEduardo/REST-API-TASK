<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use App\Controller\ControllerBoss;
use App\Controller\ControllerTask;
use App\Controller\ControllerWorker;
use PHPUnit\Framework\TestCase;

// Keeps docs/openapi.json in sync with config/routes.php
final class OpenApiTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../../docs/openapi.json';
    private const ROUTES = __DIR__ . '/../../../config/routes.php';

    private function spec(): array
    {
        $spec = json_decode(file_get_contents(self::SPEC), true);
        $this->assertIsArray($spec, 'docs/openapi.json is not valid JSON');

        return $spec;
    }

    // resource => sorted methods, from the real route table
    private function routedMethods(): array
    {
        $routes = (require self::ROUTES)(
            $this->createStub(ControllerTask::class),
            $this->createStub(ControllerWorker::class),
            $this->createStub(ControllerBoss::class),
            null
        );

        $result = [];
        foreach ($routes as $resource => $methods) {
            $result[$resource] = array_keys($methods);
            sort($result[$resource]);
        }
        ksort($result);

        return $result;
    }

    // resource => sorted methods, from the spec ("/task/{id}" and "/task" both belong to "task")
    private function documentedMethods(): array
    {
        $httpMethods = ['get', 'post', 'put', 'patch', 'delete'];
        $result = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            $resource = explode('/', trim($path, '/'))[0];
            foreach (array_keys($operations) as $method) {
                if (in_array($method, $httpMethods, true)) {
                    $result[$resource][] = strtoupper($method);
                }
            }
        }

        foreach ($result as &$methods) {
            $methods = array_values(array_unique($methods));
            sort($methods);
        }
        unset($methods);
        ksort($result);

        return $result;
    }

    public function testSpecDocumentsExactlyTheRoutedMethods(): void
    {
        $this->assertSame($this->routedMethods(), $this->documentedMethods());
    }

    public function testEveryRefPointsToAnExistingComponent(): void
    {
        $spec = $this->spec();
        preg_match_all('/"\$ref":\s*"#\/([^"]+)"/', file_get_contents(self::SPEC), $matches);

        foreach (array_unique($matches[1]) as $pointer) {
            $node = $spec;
            foreach (explode('/', $pointer) as $key) {
                $this->assertArrayHasKey($key, $node, "Broken \$ref: #/$pointer");
                $node = $node[$key];
            }
        }
    }
}
