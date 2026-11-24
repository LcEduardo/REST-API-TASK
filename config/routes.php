<?php

use App\Controller\ControllerAuth;
use App\Controller\ControllerBoss;
use App\Controller\ControllerTask;
use App\Controller\ControllerWorker;

// Builds the route table: resource => [method => handler].
// Each handler receives the id from the URL (or null) and returns ['status', 'body'].
// $role is the caller's role, used by handlers that restrict access.
// $authMethod is how the caller authenticated ('jwt' or 'api_key').
return fn(
    ControllerTask $controllerTask,
    ControllerWorker $controllerWorker,
    ControllerBoss $controllerBoss,
    ControllerAuth $controllerAuth,
    ?string $role,
    ?string $authMethod
): array => [
    // Exchanges the API key sent in X-API-Key for a JWT
    'token' => [
        'POST' => fn($id) => $id !== null
            ? error(404, 'Route not found')
            : $controllerAuth->createToken($role, $authMethod),
    ],
    // Singular for operations on one record (/task/{id}, POST /task); plural only for listing (/tasks)
    'task' => [
        'GET'  => fn($id) => requireId($id, fn($id) => $controllerTask->getTask($id)),
        'POST' => fn() => withJsonBody(fn($b) => $controllerTask->createTask(
            $b['worker_id'] ?? null, $b['boss_id'] ?? null, $b['description'] ?? null, $b['title'] ?? null
        )),
    ],
    'tasks' => [
        'GET' => fn($id) => $id !== null
            ? error(404, 'Route not found')
            : $controllerTask->getAllTasks($_GET['page'] ?? null, $_GET['per_page'] ?? null),
    ],
    'worker' => [
        'GET'  => fn($id) => requireId($id, fn($id) => $controllerWorker->getWorker($id)),
        // Only a boss can create workers (the response carries the new worker's API key)
        'POST' => fn() => requireRole($role, 'boss', fn() => withJsonBody(
            fn($b) => $controllerWorker->createWorker($b['name'] ?? null, $b['email'] ?? null)
        )),
        'PUT'  => fn($id) => requireId($id, fn($id) => withJsonBody(
            fn($b) => $controllerWorker->updateWorker($id, $b['name'] ?? null, $b['email'] ?? null)
        )),
    ],
    'boss' => [
        'GET'  => fn($id) => requireId($id, fn($id) => $controllerBoss->getBoss($id)),
        'POST' => fn() => withJsonBody(fn($b) => $controllerBoss->createBoss($b['name'] ?? null, $b['email'] ?? null)),
        'PUT'  => fn($id) => requireId($id, fn($id) => withJsonBody(
            fn($b) => $controllerBoss->updateBoss($id, $b['name'] ?? null, $b['email'] ?? null)
        )),
    ],
];
