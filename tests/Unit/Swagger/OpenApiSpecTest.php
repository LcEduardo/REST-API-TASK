<?php

namespace Tests\Unit\Swagger;

use App\Controller\ControllerBoss;
use App\Controller\ControllerTask;
use App\Controller\ControllerWorker;
use PHPUnit\Framework\TestCase;

// Keeps swagger/openapi.json valid and in sync with config/routes.php
class OpenApiSpecTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../../swagger/openapi.json';

    private function spec(): array {
        $spec = json_decode(file_get_contents(self::SPEC), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'openapi.json is not valid JSON: ' . json_last_error_msg());

        return $spec;
    }

    // Collects every "$ref" value in the document
    private function refs(array $node): array {
        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                $refs[] = $value;
            } elseif (is_array($value)) {
                $refs = array_merge($refs, $this->refs($value));
            }
        }

        return $refs;
    }

    public function testSpecIsValidOpenApi3Json(): void {
        $spec = $this->spec();

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertArrayHasKey('paths', $spec);
    }

    public function testEveryRefPointsToAnExistingComponent(): void {
        $spec = $this->spec();

        foreach ($this->refs($spec) as $ref) {
            $node = $spec;
            foreach (explode('/', substr($ref, 2)) as $part) {
                $this->assertArrayHasKey($part, $node, "Broken \$ref: $ref");
                $node = $node[$part];
            }
        }
    }

    public function testEveryRouteIsDocumented(): void {
        $paths = $this->spec()['paths'];
        $routes = (require __DIR__ . '/../../../config/routes.php')(
            $this->createStub(ControllerTask::class),
            $this->createStub(ControllerWorker::class),
            $this->createStub(ControllerBoss::class),
            null
        );

        foreach ($routes as $resource => $methods) {
            foreach (array_keys($methods) as $method) {
                $verb = strtolower($method);
                $documented = isset($paths["/$resource"][$verb]) || isset($paths["/$resource/{id}"][$verb]);

                $this->assertTrue($documented, "$method /$resource is missing from swagger/openapi.json");
            }
        }
    }

    public function testEveryDocumentedOperationExistsInRoutes(): void {
        $routes = (require __DIR__ . '/../../../config/routes.php')(
            $this->createStub(ControllerTask::class),
            $this->createStub(ControllerWorker::class),
            $this->createStub(ControllerBoss::class),
            null
        );

        foreach ($this->spec()['paths'] as $path => $item) {
            $resource = explode('/', trim($path, '/'))[0];
            foreach (array_keys($item) as $verb) {
                if ($verb === 'parameters') {
                    continue;
                }

                $this->assertArrayHasKey(strtoupper($verb), $routes[$resource] ?? [], "$verb $path is documented but has no route");
            }
        }
    }
}
