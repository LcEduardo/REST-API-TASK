# Roteamento: `index.php`, `config/routes.php` e `src/Helpers/`

Toda requisição para a API entra pelo `index.php`. Ele descobre **quem** está chamando (pela API key), **o que** foi pedido (pela URL e pelo método) e **qual código** deve responder. Depois devolve a resposta em JSON.

Para não ficar tudo num arquivo só, esse trabalho está dividido em três partes. Pense num restaurante:

| Peça | Arquivo | Papel no "restaurante" |
|---|---|---|
| O garçom | `index.php` | Recebe o cliente, anota o pedido, leva para a cozinha e entrega o prato. Não cozinha nada |
| O cardápio | `config/routes.php` | A lista de tudo que pode ser pedido e de quem prepara cada prato |
| Os utensílios | `src/Helpers/http.php` e `src/Helpers/routing.php` | Funções pequenas que o garçom e o cardápio usam o tempo todo |

Os controllers (`src/Controller/`) são a cozinha: é lá que o trabalho de verdade acontece (validar campos, consultar o banco). Este documento não entra neles.

Para ver tudo funcionando junto, vamos seguir duas requisições de verdade: `GET /task/1` (simples) e `POST /worker` (com corpo JSON e restrição de papel).

---

## Os utensílios: `src/Helpers/`

São funções soltas (sem classe), disponíveis em qualquer lugar do projeto. Elas não são carregadas com `require`: o Composer faz isso sozinho, porque estão listadas no `composer.json`:

```json
"autoload": {
    "files": [
        "src/Helpers/http.php",
        "src/Helpers/routing.php"
    ]
}
```

- `"files"` diz ao Composer: "toda vez que alguém carregar o `vendor/autoload.php`, carregue também estes arquivos".
- Por isso as funções funcionam tanto no `index.php` quanto nos testes, sem `require` nenhum.
- **Criou um arquivo novo de helpers?** Adicione na lista e rode `composer dump-autoload`. Sem isso, o PHP não acha as funções.

### O formato da resposta

Antes das funções, uma convenção importante. Tudo que responde uma requisição (os controllers e os helpers) devolve um array neste formato:

```php
['status' => 200, 'body' => [...]]
```

- `status`: o código HTTP (`200`, `400`, `404`...).
- `body`: o que vai virar o JSON da resposta.
- Opcionalmente, `headers`: headers extras para enviar, como `['Allow' => 'GET, POST']`.

Ninguém dá `echo` no meio do caminho. Só no final, uma única função (`respond`) transforma esse array na resposta de verdade. Isso permite testar tudo olhando só o array, sem servidor.

### `src/Helpers/http.php`

#### `respond()`: envia a resposta

> *"Pega o array de resposta e manda para quem chamou a API."*

```php
// src/Helpers/http.php:4-10
function respond(int $status, $body, array $headers = []): void {
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header("$name: $value");
    }
    echo json_encode($body);
}
```

- `http_response_code($status)` define o código HTTP.
- O `foreach` envia cada header extra. `['Allow' => 'GET, POST']` vira o header `Allow: GET, POST`.
- `echo json_encode($body)` transforma o array em texto JSON e envia.
- É a **única** função do roteamento que tem efeito "para fora". Por isso é chamada uma vez só, na última linha do `index.php`.

#### `error()`: monta uma resposta de erro

> *"Atalho para não repetir `['status' => ..., 'body' => ['error' => ...]]` em todo lugar."*

```php
// src/Helpers/http.php:13-15
function error(int $status, string $message): array {
    return ['status' => $status, 'body' => ['error' => $message]];
}
```

- `error(404, 'Route not found')` devolve `['status' => 404, 'body' => ['error' => 'Route not found']]`.
- Repare que ela **não envia** nada, só monta o array. Quem envia é o `respond()`.

#### `routeId()`: pega o id da URL

> *"Em `/task/1`, o id é o segundo pedaço. Se ele não veio, devolve `null`."*

```php
// src/Helpers/http.php:18-21
function routeId(array $segments): ?string {
    $id = $segments[1] ?? null;
    return ($id === null || $id === '') ? null : $id;
}
```

- `$segments` é a URL já cortada em pedaços: `['task', '1']`. O id é a posição `[1]`.
- `?? null`: em `/task` não existe posição `[1]`, então vira `null`.
- O `''` também vira `null`, para que `/task/` (com barra no fim) seja tratado igual a `/task`.

#### Os "porteiros": `requireId()`, `requireRole()` e `withJsonBody()`

Essas três funções seguem o mesmo padrão: **conferem uma coisa e, se estiver tudo certo, chamam a próxima etapa** (`$next`). Se não estiver, devolvem um erro e a próxima etapa nunca roda.

```php
// src/Helpers/http.php:24-26
function requireId(?string $id, callable $next): array {
    return $id === null ? error(400, 'ID not provided') : $next($id);
}
```

- `callable $next` é uma função recebida como parâmetro: "o que fazer se der certo".
- Sem id: devolve `400`. Com id: chama `$next($id)` e devolve o que ela devolver.

```php
// src/Helpers/http.php:29-31
function requireRole(?string $role, string $required, callable $next): array {
    return $role === $required ? $next() : error(403, 'Access Denied');
}
```

- Compara o papel de quem chamou (`'boss'` ou `'worker'`) com o papel exigido. Se for diferente, devolve `403` ("proibido").
- **`401` x `403`:** o `401` (veja o `dispatch()` mais abaixo) quer dizer "não sei quem você é". O `403` quer dizer "sei quem você é, mas você não pode fazer isso".

```php
// src/Helpers/http.php:34-42
function withJsonBody(callable $next): array {
    $body = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
        return error(400, 'Invalid JSON body');
    }

    return $next($body);
}
```

- `file_get_contents('php://input')` lê o corpo da requisição (o JSON que veio no `POST` ou `PUT`).
- `json_decode(..., true)` transforma o texto em array. O `true` pede array em vez de objeto.
- `json_last_error() !== JSON_ERROR_NONE`: o JSON veio quebrado (ex.: `{x`).
- `!is_array($body)`: o JSON é válido, mas não é um objeto (ex.: só `"oi"` ou `42`). O código espera campos como `name` e `email`, então isso também é `400`.
- Tudo certo: chama `$next($body)` entregando o array já pronto.

**Por que esse padrão de `$next`?** Porque dá para encaixar um porteiro dentro do outro, como bonecas russas. É exatamente isso que o cardápio faz (veja `config/routes.php` abaixo).

### `src/Helpers/routing.php`

#### `parseSegments()`: corta a URL em pedaços

> *"Transforma `/task/1?page=2` em `['task', '1']`."*

```php
// src/Helpers/routing.php:5-12
function parseSegments(string $scriptName, string $requestUri): array {
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
    $path = substr($path, strlen($base));

    return explode('/', trim($path, '/'));
}
```

`$base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');`
- `$scriptName` é o caminho do `index.php` (ex.: `/index.php` ou `/api/index.php`). `dirname` pega só a pasta: `/` ou `/api`.
- `str_replace('\\', '/', ...)` troca a barra invertida do Windows pela normal.
- `rtrim(..., '/')` tira a barra do fim. Resultado: `""` se a API está na raiz, ou `"/api"` se está numa subpasta.

`$path = parse_url($requestUri, PHP_URL_PATH) ?? '';`
- `$requestUri` é o que foi pedido: `/api/task/1?page=2`. `parse_url(..., PHP_URL_PATH)` fica só com o caminho, sem o `?page=2`.
- `?? ''`: se a URL for estranha demais e o `parse_url` falhar, usa texto vazio em vez de quebrar.

`$path = substr($path, strlen($base));`
- Corta o começo da subpasta. `/api/task/1` vira `/task/1`. Na raiz, `$base` é `""` e nada é cortado.

`return explode('/', trim($path, '/'));`
- `trim(..., '/')` tira as barras das pontas: `task/1`.
- `explode('/', ...)` corta nas barras: `['task', '1']`.

**Por que recebe `$scriptName` e `$requestUri` como parâmetros, em vez de ler `$_SERVER` direto?** Para dar para testar. O teste pode passar qualquer URL e conferir o resultado, sem servidor rodando.

#### `dispatch()`: decide qual resposta dar

> *"Olha quem chamou e o que foi pedido, e decide: erro ou chamar o código da rota."*

```php
// src/Helpers/routing.php:17-41
function dispatch(array $routes, string $method, array $segments, ?string $role, bool $authFailed): array {
    $resource = $segments[0] ?? '';

    if ($authFailed) {
        return error(500, 'Database error');
    }

    if ($role === null) {
        return error(401, 'Invalid or missing API key');
    }

    if (!isset($routes[$resource])) {
        return error(404, 'Route not found');
    }

    if (!isset($routes[$resource][$method])) {
        return error(405, 'Method not allowed')
            + ['headers' => ['Allow' => implode(', ', array_keys($routes[$resource]))]];
    }

    return $routes[$resource][$method](routeId($segments));
}
```

`$resource = $segments[0] ?? '';`
- O recurso é o primeiro pedaço da URL: `task`, `tasks`, `worker` ou `boss`.

As checagens rodam **nesta ordem**, e a primeira que falhar já devolve a resposta:

| # | Checagem | Resposta | Por quê |
|---|---|---|---|
| 1 | `$authFailed` | `500` | O banco caiu enquanto a chave de um worker era conferida. Não é culpa de quem chamou |
| 2 | `$role === null` | `401` | Sem chave ou chave inválida. Vem **antes** do `404` para que quem não tem chave não consiga descobrir quais rotas existem |
| 3 | O recurso não está no cardápio | `404` | Ex.: `/nope` |
| 4 | O recurso existe, mas não aceita o método | `405` + header `Allow` | Ex.: `DELETE /task/1`. O `Allow` avisa quais métodos funcionam |
| 5 | Tudo certo | O que a rota devolver | Chama a função da rota passando o id |

Sobre a checagem 4:
- `array_keys($routes['task'])` devolve os métodos cadastrados: `['GET', 'POST']`. O `implode(', ', ...)` junta em `'GET, POST'`. Assim o header nunca fica desatualizado: cadastrou um método novo no cardápio, ele aparece no `Allow` sozinho.
- O `+` junta dois arrays. O resultado é `['status' => 405, 'body' => [...], 'headers' => ['Allow' => 'GET, POST']]`.
- O header **não** é enviado aqui (sem `header()`). Ele só vai no array, e quem envia é o `respond()`. Assim o `dispatch()` não tem efeito nenhum "para fora" e pode ser testado só olhando o que devolve.

Sobre a checagem 5:
- `$routes['task']['GET']` é uma função (veja o cardápio abaixo). Os parênteses no fim, `(routeId($segments))`, chamam essa função passando o id.

---

## O cardápio: `config/routes.php`

> *"A lista de tudo que a API sabe fazer: para cada recurso e método, qual função responde."*

O arquivo não declara nada solto: ele **devolve uma função** que monta o cardápio.

```php
// config/routes.php:10-15
return fn(
    ControllerTask $controllerTask,
    ControllerWorker $controllerWorker,
    ControllerBoss $controllerBoss,
    ?string $role
): array => [
    // ...rotas...
];
```

- `return` num arquivo PHP faz o `require` desse arquivo devolver um valor. Aqui, o valor é uma função.
- **Por que uma função e não o array direto?** Porque as rotas precisam dos controllers e do `$role`, que são criados no `index.php`. A função recebe essas peças como parâmetros, em vez de depender de variáveis "soltas" que por acaso existem no arquivo que fez o `require`. Fica claro do que o cardápio precisa.

O formato é **recurso => [método => função]**:

```php
// config/routes.php:17-22
'task' => [
    'GET'  => fn($id) => requireId($id, fn($id) => $controllerTask->getTask($id)),
    'POST' => fn() => withJsonBody(fn($b) => $controllerTask->createTask(
        $b['worker_id'] ?? null, $b['boss_id'] ?? null, $b['description'] ?? null, $b['title'] ?? null
    )),
],
```

- `'task'` é o recurso (primeiro pedaço da URL).
- `'GET' => fn($id) => ...` é a função que responde `GET /task/...`. Ela recebe o id que o `dispatch()` passa.
- Repare nos porteiros encaixados: `requireId(...)` confere o id e só então chama `fn($id) => $controllerTask->getTask($id)`.
- No `POST`, `withJsonBody(...)` lê o JSON e entrega em `$b`. Os campos são lidos com `?? null`, para que um campo faltando chegue como `null` ao controller (que responde `400` explicando o que falta), em vez de dar erro no PHP.

O exemplo com mais porteiros é o de criar worker:

```php
// config/routes.php:31-33
'POST' => fn() => requireRole($role, 'boss', fn() => withJsonBody(
    fn($b) => $controllerWorker->createWorker($b['name'] ?? null, $b['email'] ?? null)
)),
```

Lendo de fora para dentro:
1. `requireRole($role, 'boss', ...)`: só passa se quem chamou for boss. Senão, `403`.
2. `withJsonBody(...)`: só passa se o corpo for um JSON válido. Senão, `400`.
3. `$controllerWorker->createWorker(...)`: cria o worker de fato.

A ordem importa: um worker que mandar JSON quebrado recebe `403`, não `400`. Ele nem chega a ter o corpo lido, porque não teria permissão de qualquer jeito.

**Para adicionar uma rota nova:** acrescente uma linha no cardápio. O `dispatch()` e o header `Allow` já passam a enxergá-la, sem mexer em mais nada.

---

## O garçom: `index.php`

O `index.php` só junta as peças. Ele faz quatro coisas, nesta ordem:

**1. Monta models e controllers** (`index.php:14-21`). Cada controller recebe o model de que precisa. Nada é executado ainda.

**2. Descobre quem está chamando** (`index.php:28-40`). O `ApiKeyAuth` olha o header `X-API-Key` e devolve `'boss'`, `'worker'` ou `null` em `$role`. Se o banco falhar durante essa conferência, `$authFailed` vira `true`. Os detalhes estão em [autorizacao-api-keys.md](autorizacao-api-keys.md).

**3. Monta o cardápio, corta a URL e decide a resposta:**

```php
// index.php:42-44
$routes   = (require __DIR__ . '/config/routes.php')($controllerTask, $controllerWorker, $controllerBoss, $role);
$segments = parseSegments($_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI']);
$response = dispatch($routes, $_SERVER['REQUEST_METHOD'], $segments, $role, $authFailed);
```

- `(require __DIR__ . '/config/routes.php')` devolve a função do cardápio. O `(...)` logo depois chama essa função, entregando os controllers e o `$role`. O resultado é o array de rotas.
- `parseSegments(...)` recebe o caminho do `index.php` e a URL pedida, e devolve os pedaços.
- `dispatch(...)` decide a resposta.
- Repare que **só o `index.php` lê `$_SERVER`**. Ele tira dali os valores e entrega como parâmetros. É isso que permite testar `parseSegments` e `dispatch` sem servidor.

**4. Envia a resposta:**

```php
// index.php:46
respond($response['status'], $response['body'], $response['headers'] ?? []);
```

- `?? []`: a maioria das respostas não tem `headers`. Nesse caso, passa uma lista vazia.

---

## Exemplo 1: `GET /task/1`

```
curl http://localhost:8000/task/1 -H "X-API-Key: <chave de boss>"
```

| Etapa | Onde | O que acontece |
|---|---|---|
| 1 | `index.php` | `$role` vira `'boss'` |
| 2 | `parseSegments` | `/task/1` vira `['task', '1']` |
| 3 | `dispatch` | Banco ok, `$role` não é `null`, `task` existe, `GET` existe. Chama `$routes['task']['GET']('1')` |
| 4 | `requireId` | O id é `'1'`, não é `null`. Chama `getTask('1')` |
| 5 | Controller | Busca a task e devolve `['status' => 200, 'body' => [...]]` |
| 6 | `respond` | Envia `200` com o JSON da task |

E se faltar o id (`GET /task`)? Na etapa 2 sai `['task']`, o `routeId` devolve `null`, e na etapa 4 o `requireId` responde `400` com `{"error": "ID not provided"}`. O controller nem é chamado.

## Exemplo 2: `POST /worker`

```
curl -X POST http://localhost:8000/worker -H "X-API-Key: <chave de boss>" -d '{"name": "Ana", "email": "ana@exemplo.com"}'
```

| Etapa | Onde | O que acontece |
|---|---|---|
| 1 | `index.php` | `$role` vira `'boss'` |
| 2 | `parseSegments` | `/worker` vira `['worker']` |
| 3 | `dispatch` | Tudo ok. Chama `$routes['worker']['POST'](null)`. O id é `null`, mas essa rota nem usa id |
| 4 | `requireRole` | `'boss' === 'boss'`, passa |
| 5 | `withJsonBody` | O JSON é válido. Entrega `['name' => 'Ana', 'email' => 'ana@exemplo.com']` |
| 6 | Controller | Cria o worker e devolve `201` com os dados (incluindo a API key gerada) |
| 7 | `respond` | Envia a resposta |

Com a chave de um worker, a etapa 4 para tudo e a resposta é `403` com `{"error": "Access Denied"}`.

---

## Resumo de todas as situações

| Situação | Quem responde | Resposta |
|---|---|---|
| Banco falhou ao conferir a chave | `dispatch` | `500` |
| Sem chave ou chave inválida (em qualquer URL) | `dispatch` | `401` |
| Recurso inexistente (`/nope`) | `dispatch` | `404` |
| Método não aceito (`DELETE /task/1`) | `dispatch` | `405` + `Allow: GET, POST` |
| Rota que exige id, sem id (`GET /task`) | `requireId` | `400` |
| Worker tentando `POST /worker` | `requireRole` | `403` |
| Corpo JSON quebrado ou que não é objeto | `withJsonBody` | `400` |
| Tudo certo | O controller | O que ele devolver |

## Como isso é testado

Os testes ficam em `tests/Unit/Helpers/RoutingTest.php` e rodam com `composer test`.

- `parseSegments` é testado com várias URLs: na raiz, numa subpasta, com `?query`, com barra no fim e vazia.
- `dispatch` é testado com um **cardápio falso**: em vez dos controllers de verdade, cada rota é uma função que só devolve o id que recebeu. Assim dá para testar todas as checagens (`500`, `401`, `404`, `405` com `Allow`, e a chamada da rota com e sem id) sem banco nem servidor.

Isso só é possível porque nenhuma das duas funções lê `$_SERVER` ou envia nada sozinha: elas recebem valores e devolvem um resultado.
