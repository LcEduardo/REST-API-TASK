<?php

require __DIR__ . "/vendor/autoload.php";
require __DIR__ . "/config/database.php";

use App\Auth\ApiKeyAuth;
use App\Controller\ControllerBoss;
use App\Controller\ControllerTask;
use App\Controller\ControllerWorker;
use App\Model\Boss;
use App\Model\Task;
use App\Model\Worker;

$Worker = new Worker($db);
$controllerWorker = new ControllerWorker($Worker);

$Boss = new Boss($db);
$controllerBoss = new ControllerBoss($Boss);

$Task = new Task($db);
$controllerTask = new ControllerTask($Task, $controllerWorker, $controllerBoss);

header('Content-Type: application/json');

function respond(int $status, $body): void {
    http_response_code($status);
    echo json_encode($body);
}

// Resposta de erro no mesmo formato que os controllers devolvem
function error(int $status, string $message): array {
    return ['status' => $status, 'body' => ['error' => $message]];
}

// id de /recurso/{id}, ou null se não foi enviado
function routeId(array $segments): ?string {
    $id = $segments[1] ?? null;
    return ($id === null || $id === '') ? null : $id;
}

// Só chama $next se o id foi enviado; senão responde 400
function requireId(?string $id, callable $next): array {
    return $id === null ? error(400, 'ID not provided') : $next($id);
}

// Lê o corpo JSON e passa para $next. Se for inválido (ou não for um objeto), responde 400.
function withJsonBody(callable $next): array {
    $body = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
        return error(400, 'Invalid JSON body');
    }

    return $next($body);
}

// recurso => [método => handler]. Cada handler recebe o id da URL (ou null) e devolve ['status', 'body'].
$routes = [
    // singular para operações em um registro (/task/{id}, POST /task); plural só para listagem (/tasks)
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
        'POST' => fn() => withJsonBody(fn($b) => $controllerWorker->createWorker($b['name'] ?? null, $b['email'] ?? null)),
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

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/'); // "" na raiz, ou "/subpasta" se o index.php estiver em uma
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); // /tasks/1
$path = substr($path, strlen($base)); // /tasks/1
$segments = explode('/', trim($path, '/'));  // ["tasks"] ou ["tasks", "1"]
$resource = $segments[0]; // "tasks"
$method = $_SERVER['REQUEST_METHOD'];

// Chave enviada no header X-API-Key -> papel ('boss' ou 'worker'). Por enquanto os dois acessam tudo.
$auth = new ApiKeyAuth([
    'boss'   => $_ENV['API_SECRET_KEY_BOSS'] ?? null,
    'worker' => $_ENV['API_SECRET_KEY_WORKER'] ?? null,
]);
$role = $auth->roleFor($_SERVER['HTTP_X_API_KEY'] ?? null);

if ($role === null) {
    // sem chave ou chave inválida -> 401, antes de olhar a rota (não revela quais rotas existem)
    $response = error(401, 'Invalid or missing API key');
} elseif (!isset($routes[$resource])) {
    // rota não existe -> 404 (erro do cliente, por isso não é 500)
    $response = error(404, 'Route not found');
} elseif (!isset($routes[$resource][$method])) {
    // rota existe, mas o método não é suportado -> 405 (Allow gerado a partir da tabela de rotas)
    header('Allow: ' . implode(', ', array_keys($routes[$resource])));
    $response = error(405, 'Method not allowed');
} else {
    $response = $routes[$resource][$method](routeId($segments));
}

respond($response['status'], $response['body']);
