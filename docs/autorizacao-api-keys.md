# Autorização por API key

Só quem tem uma **chave** (API key) pode usar a API. A chave é um texto comprido e secreto que vai junto de cada requisição, num header chamado `X-API-Key`. Pense nela como o crachá que você mostra na portaria antes de entrar no prédio.

Hoje existem dois crachás:

| Chave | Variável no `.env` | Pode acessar |
|---|---|---|
| Boss (chefe) | `API_SECRET_KEY_BOSS` | Todas as rotas |
| Worker (funcionário) | `API_SECRET_KEY_WORKER` | Todas as rotas (por enquanto) |

> O plano é, depois, o worker **não** poder criar tasks nem editar registros (`POST` e `PUT`). A peça que vai permitir isso já existe: a variável `$role`, explicada mais abaixo.

## Quem participa

| Peça | Arquivo | Papel na "portaria" |
|---|---|---|
| As chaves | `.env` | A lista de crachás válidos. Fica fora do git |
| `ApiKeyAuth::roleFor()` | `src/Auth/ApiKeyAuth.php` | O conferente: olha o crachá e diz de quem ele é |
| A checagem | `index.php:94-113` | O porteiro: pergunta ao conferente e decide se deixa passar |

Para ver tudo funcionando junto, vamos seguir uma requisição de verdade, `GET /task/1`, do começo ao fim.

---

## Passo 1: a requisição chega

```
curl http://localhost:8000/task/1 -H "X-API-Key: <valor de API_SECRET_KEY_WORKER>"
```

- `http://localhost:8000/task/1`: o endereço. Queremos a task de id `1`.
- `-H "X-API-Key: ..."`: o header com o crachá. Aqui estamos usando a chave de worker.

Todo pedido para a API passa primeiro pelo `index.php`. É lá que a portaria fica.

---

## Passo 2: as chaves do `.env` são carregadas

> *"Lê o arquivo `.env` e coloca cada variável dentro de `$_ENV`, para o resto do código poder usar."*

```php
// config/database.php:7-8
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
```

- `Dotenv::createImmutable(...)` prepara a leitura do `.env` que fica na raiz do projeto. A biblioteca é a `vlucas/phpdotenv`, instalada pelo Composer.
- `safeLoad()` lê o arquivo e coloca as variáveis em `$_ENV`. Depois disso, `$_ENV['API_SECRET_KEY_BOSS']` tem o valor da chave de boss.
- O `index.php` faz `require` desse arquivo logo no começo (`index.php:4`), então as chaves já estão disponíveis quando a checagem roda.

---

## Passo 3: o porteiro monta o conferente

> *"Cria o conferente entregando a ele a lista de crachás válidos, cada um com o nome do seu dono."*

```php
// index.php:95-98
$auth = new ApiKeyAuth([
    'boss'   => $_ENV['API_SECRET_KEY_BOSS'] ?? null,
    'worker' => $_ENV['API_SECRET_KEY_WORKER'] ?? null,
]);
```

- `new ApiKeyAuth([...])` cria o conferente. Ele recebe uma lista no formato **dono => chave**.
- `'boss' => $_ENV['API_SECRET_KEY_BOSS']`: "a chave que está em `API_SECRET_KEY_BOSS` pertence ao boss".
- `?? null`: se a variável não existir no `.env`, usa `null` (nada) em vez de dar erro. O conferente sabe lidar com isso (veja o passo 4).

O construtor da classe só guarda essa lista para usar depois:

```php
// src/Auth/ApiKeyAuth.php:11-13
public function __construct(private array $keys)
{
}
```

- `private array $keys` é um atalho do PHP 8: recebe a lista e já guarda em `$this->keys`, sem precisar escrever mais nada dentro das chaves `{ }`.

---

## Passo 4: o conferente olha o crachá

> *"Recebe a chave que veio no header e devolve o nome do dono (`'boss'` ou `'worker'`). Se a chave não for de ninguém, devolve `null`."*

Primeiro, o porteiro pega a chave do header e entrega ao conferente:

```php
// index.php:99
$role = $auth->roleFor($_SERVER['HTTP_X_API_KEY'] ?? null);
```

- O PHP coloca os headers da requisição dentro de `$_SERVER`, mudando o nome: tudo em maiúsculas, com `HTTP_` na frente e `-` virando `_`. Por isso `X-API-Key` vira `HTTP_X_API_KEY`.
- `?? null`: se o header não veio, passa `null`.
- O resultado (o dono da chave) fica guardado em `$role` ("papel", em inglês).

Agora, dentro do conferente:

```php
// src/Auth/ApiKeyAuth.php:16-35
public function roleFor(?string $key): ?string
{
    if ($key === null || $key === '') {
        return null;
    }

    foreach ($this->keys as $role => $expected) {
        if ($expected === null || $expected === '') {
            continue;
        }

        if (hash_equals($expected, $key)) {
            return $role;
        }
    }

    return null;
}
```

`if ($key === null || $key === '') { return null; }`
- Se não veio header nenhum (`null`) ou ele veio vazio (`''`), nem perde tempo: devolve `null`, ou seja, "crachá inválido".

`foreach ($this->keys as $role => $expected)`
- Passa pela lista de crachás válidos, um de cada vez. Na primeira volta, `$role` é `'boss'` e `$expected` é a chave de boss. Na segunda, `'worker'` e a chave de worker.

`if ($expected === null || $expected === '') { continue; }`
- Se aquela chave não foi configurada no `.env` (está faltando ou vazia), pula para a próxima.
- **Por que isso importa:** sem essa linha, se `API_SECRET_KEY_WORKER` estivesse vazia, alguém que mandasse o header vazio seria aceito como worker, porque "vazio" seria igual a "vazio".

`if (hash_equals($expected, $key)) { return $role; }`
- Compara a chave enviada com a chave da lista. Se forem iguais, devolve o dono: `'boss'` ou `'worker'`.
- **Por que `hash_equals` e não `===`:** o `===` compara letra por letra e para na primeira diferente. Então uma chave errada logo na primeira letra é recusada um pouquinho mais rápido do que uma errada só na última. Medindo esses tempos com muitas tentativas, alguém poderia descobrir a chave aos poucos. O `hash_equals` sempre leva o mesmo tempo, não importa onde está a diferença.

`return null;`
- Passou pela lista inteira e nenhuma chave bateu: crachá inválido.

**No nosso exemplo:** a chave enviada é a de worker. Na primeira volta ela é comparada com a de boss e não bate. Na segunda, é comparada com a de worker e bate. `roleFor` devolve `'worker'`.

---

## Passo 5: o porteiro decide

> *"Se o crachá for inválido, barra na porta com 401. Se for válido, deixa seguir para a rota pedida."*

```php
// index.php:101-113
if ($role === null) {
    $response = error(401, 'Invalid or missing API key');
} elseif (!isset($routes[$resource])) {
    $response = error(404, 'Route not found');
} elseif (!isset($routes[$resource][$method])) {
    header('Allow: ' . implode(', ', array_keys($routes[$resource])));
    $response = error(405, 'Method not allowed');
} else {
    $response = $routes[$resource][$method](routeId($segments));
}
```

`if ($role === null)`
- O conferente disse que o crachá não é de ninguém. A resposta é `401` ("não autorizado") e nada mais é feito: não procura a rota, não chama controller, não consulta o banco.
- Repare que essa é a **primeira** checagem, antes até de ver se a rota existe. Assim, quem não tem chave recebe `401` em qualquer endereço, inclusive nos que não existem. Se fosse o contrário, alguém sem chave poderia testar endereços e descobrir quais rotas existem (as que dessem `401`) e quais não existem (as que dessem `404`).

`elseif (!isset($routes[$resource]))` e `elseif (!isset($routes[$resource][$method]))`
- Só chega aqui quem tem crachá válido. Essas são as checagens de rota que já existiam: `404` se o endereço não existe, `405` se existe mas não aceita aquele método.

`else { $response = $routes[$resource][$method](routeId($segments)); }`
- Tudo certo: chama o código responsável pela rota pedida.

**No nosso exemplo:** `$role` é `'worker'` (não é `null`), a rota `task` existe e aceita `GET`. Então cai no `else`.

---

## Passo 6: a task é buscada e a resposta volta

```php
// index.php:61
'GET'  => fn($id) => requireId($id, fn($id) => $controllerTask->getTask($id)),
```

- `requireId` confere se o id veio na URL. Veio: é `'1'`.
- `$controllerTask->getTask('1')` busca a task no banco (`src/Controller/ControllerTask.php:21`).

A resposta é `200` com a task:

```json
{"id": 1, "title": "Create login page", "status": "pending", "worker": "Ana Silva", "boss": "Fernando Alves", ...}
```

---

## E se a chave estiver errada ou faltando?

```
curl http://localhost:8000/task/1
```

| Passo | O que muda |
|---|---|
| 4 | O header não veio, então `roleFor` recebe `null` e devolve `null` logo na primeira linha. Com uma chave errada, ela é comparada com as duas e, como nenhuma bate, devolve `null` no final |
| 5 | `$role` é `null`, então o porteiro responde `401` ali mesmo |
| 6 | Não acontece. O controller e o banco nem ficam sabendo da requisição |

```json
{"error": "Invalid or missing API key"}
```

---

## Resumo de todas as situações

| Situação | `roleFor` devolve | Resposta |
|---|---|---|
| Chave de boss | `'boss'` | Segue para a rota |
| Chave de worker | `'worker'` | Segue para a rota |
| Sem header `X-API-Key` | `null` | `401` |
| Header vazio | `null` | `401` |
| Chave errada (ou só parecida, ou com letras maiúsculas/minúsculas trocadas) | `null` | `401` |
| A chave foi enviada, mas a variável no `.env` está vazia ou faltando | `null` | `401` |
| Chave válida, mas rota inexistente | `'boss'` ou `'worker'` | `404` |

## Como isso é testado

Os testes ficam em `tests/Unit/Auth/ApiKeyAuthTest.php` e rodam com `composer test`. Como a classe `ApiKeyAuth` só recebe valores e devolve um resultado (ela não lê o `.env` nem o header sozinha), dá para testar todas as situações da tabela acima sem subir servidor nem banco.

## Como gerar uma chave nova

```
php -r "echo bin2hex(random_bytes(32));"
```

- `random_bytes(32)` sorteia 32 bytes aleatórios, seguros para senhas.
- `bin2hex(...)` transforma esses bytes em 64 letras e números, um texto que dá para colar no `.env`.

Depois de trocar uma chave no `.env`, quem usava a antiga passa a receber `401` e precisa da nova.
