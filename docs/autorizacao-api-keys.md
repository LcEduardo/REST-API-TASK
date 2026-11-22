# Autorização por API key

Só quem tem uma **chave** (API key) pode usar a API. A chave é um texto comprido e secreto que vai junto de cada requisição, num header chamado `X-API-Key`. Pense nela como o crachá que você mostra na portaria antes de entrar no prédio.

Hoje existem três tipos de crachá:

| Chave | Onde fica | Papel | Pode acessar |
|---|---|---|---|
| Boss (chefe) | `API_SECRET_KEY_BOSS` no `.env` | `boss` | Todas as rotas |
| Worker (funcionário) | `API_SECRET_KEY_WORKER` no `.env` | `worker` | Todas as rotas, menos `POST /worker` |
| Chave própria de cada worker | Gerada no `POST /worker`. O banco guarda só o hash, na coluna `api_key_hash` | `worker` | As mesmas da chave de worker |

> Quem decide o que cada papel pode fazer é a variável `$role`, explicada mais abaixo. Hoje ela é usada no `POST /worker`, que só o boss pode chamar (`requireRole`, explicado em [roteamento-e-helpers.md](roteamento-e-helpers.md)).

## Quem participa

| Peça | Arquivo | Papel na "portaria" |
|---|---|---|
| As chaves fixas | `.env` | A lista de crachás válidos. Fica fora do git |
| Os hashes das chaves de worker | Tabela `workers`, coluna `api_key_hash` | A lista de crachás emitidos para cada funcionário |
| `ApiKeyAuth::roleFor()` | `src/Auth/ApiKeyAuth.php` | O conferente: olha o crachá e diz de quem ele é |
| `Worker::findIdByApiKeyHash()` | `src/Model/Worker.php` | Procura um hash na tabela `workers`, a pedido do conferente |
| A chamada ao conferente | `index.php:28-40` | O porteiro: entrega o crachá ao conferente e anota a resposta em `$role` |
| A decisão | `dispatch()` em `src/Helpers/routing.php` | Barra com `401` quem não tem crachá válido, antes de olhar a rota |

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

> *"Cria o conferente entregando a ele a lista de crachás fixos, cada um com o nome do seu dono, e o acesso à tabela de workers."*

```php
// index.php:28-31
$auth = new ApiKeyAuth([
    'boss'   => $_ENV['API_SECRET_KEY_BOSS'] ?? null,
    'worker' => $_ENV['API_SECRET_KEY_WORKER'] ?? null,
], $Worker);
```

- `new ApiKeyAuth([...], $Worker)` cria o conferente. O primeiro parâmetro é uma lista no formato **dono => chave**.
- `'boss' => $_ENV['API_SECRET_KEY_BOSS']`: "a chave que está em `API_SECRET_KEY_BOSS` pertence ao boss".
- `?? null`: se a variável não existir no `.env`, usa `null` (nada) em vez de dar erro. O conferente sabe lidar com isso (veja o passo 4).
- `$Worker` é o model da tabela `workers`. Com ele, o conferente também consegue reconhecer as chaves geradas no `POST /worker`.

O construtor da classe só guarda essas peças para usar depois:

```php
// src/Auth/ApiKeyAuth.php:14-16
public function __construct(private array $keys, private ?Worker $workers = null)
{
}
```

- `private array $keys` é um atalho do PHP 8: recebe a lista e já guarda em `$this->keys`, sem precisar escrever mais nada dentro das chaves `{ }`.
- `private ?Worker $workers = null`: o `?` quer dizer que pode ser `null`, e o `= null` torna o parâmetro opcional. Sem ele, o conferente só aceita as chaves do `.env`. É assim que os testes criam o conferente quando não querem banco.

---

## Passo 4: o conferente olha o crachá

> *"Recebe a chave que veio no header e devolve o nome do dono (`'boss'` ou `'worker'`). Se a chave não for de ninguém, devolve `null`."*

Primeiro, o porteiro pega a chave do header e entrega ao conferente:

```php
// index.php:34-40
$authFailed = false;
try {
    $role = $auth->roleFor($_SERVER['HTTP_X_API_KEY'] ?? null);
} catch (PDOException $e) {
    $role = null;
    $authFailed = true;
}
```

- O PHP coloca os headers da requisição dentro de `$_SERVER`, mudando o nome: tudo em maiúsculas, com `HTTP_` na frente e `-` virando `_`. Por isso `X-API-Key` vira `HTTP_X_API_KEY`.
- `?? null`: se o header não veio, passa `null`.
- O resultado (o dono da chave) fica guardado em `$role` ("papel", em inglês).
- **Por que o `try/catch`:** para conferir as chaves de worker, o conferente consulta o banco. Se o banco falhar, o PHP lança uma `PDOException`. Sem o `catch`, isso viraria um erro fatal com uma página de erro do PHP. Com ele, `$authFailed` vira `true` e a API responde um `500` em JSON (veja o passo 5).

Agora, dentro do conferente:

```php
// src/Auth/ApiKeyAuth.php:19-45
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

    if ($this->workers !== null && $this->workers->findIdByApiKeyHash(hash('sha256', $key)) !== null) {
        return 'worker';
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

`if ($this->workers !== null && $this->workers->findIdByApiKeyHash(hash('sha256', $key)) !== null) { return 'worker'; }`
- Nenhuma chave do `.env` bateu. Agora o conferente tenta as chaves geradas para cada worker no `POST /worker`.
- O banco **não guarda a chave**, só o seu hash SHA-256: uma "impressão digital" de 64 caracteres. Dá para calcular o hash a partir da chave, mas não o contrário. Assim, se alguém conseguir ler o banco, não leva nenhuma chave que funcione.
- Por isso o conferente calcula o hash do que foi enviado (`hash('sha256', $key)`) e pede ao model para procurar esse hash (`findIdByApiKeyHash`). Se achar um worker, a chave é dele: devolve `'worker'`.
- `$this->workers !== null`: se o conferente foi criado sem o model (como nos testes), pula essa parte.
- **E o ataque pelo tempo, do `hash_equals`?** Aqui a comparação é feita pelo banco, e uma diferença de tempo só poderia revelar pedaços do hash. Isso não ajuda ninguém, porque o hash não serve como chave.

`return null;`
- Nenhuma chave do `.env` bateu e nenhum worker tem esse hash: crachá inválido.

**No nosso exemplo:** a chave enviada é a de worker do `.env`. Na primeira volta ela é comparada com a de boss e não bate. Na segunda, é comparada com a de worker e bate. `roleFor` devolve `'worker'` sem nem consultar o banco.

---

## Passo 5: o porteiro decide

> *"Se o crachá for inválido, barra na porta com 401. Se for válido, deixa seguir para a rota pedida."*

O `index.php` entrega `$role` e `$authFailed` para a função `dispatch()`, que decide a resposta:

```php
// index.php:44
$response = dispatch($routes, $_SERVER['REQUEST_METHOD'], $segments, $role, $authFailed);
```

```php
// src/Helpers/routing.php:17-41 (trecho)
if ($authFailed) {
    return error(500, 'Database error');
}

if ($role === null) {
    return error(401, 'Invalid or missing API key');
}

if (!isset($routes[$resource])) {
    return error(404, 'Route not found');
}
// ...405 e a chamada da rota
```

`if ($authFailed)`
- O banco falhou enquanto o conferente procurava a chave de um worker. Não dá para saber se a chave vale, e a culpa não é de quem chamou. A resposta é `500`.

`if ($role === null)`
- O conferente disse que o crachá não é de ninguém. A resposta é `401` ("não autorizado") e nada mais é feito: não procura a rota, não chama controller.
- Repare que essa checagem vem **antes** de ver se a rota existe. Assim, quem não tem chave recebe `401` em qualquer endereço, inclusive nos que não existem. Se fosse o contrário, alguém sem chave poderia testar endereços e descobrir quais rotas existem (as que dessem `401`) e quais não existem (as que dessem `404`).

Depois disso
- Só chega aqui quem tem crachá válido. Vêm as checagens de rota: `404` se o endereço não existe, `405` se existe mas não aceita aquele método. Se tudo estiver certo, chama o código responsável pela rota pedida.
- O `dispatch()` completo, linha por linha, está em [roteamento-e-helpers.md](roteamento-e-helpers.md).

**No nosso exemplo:** o banco não falhou e `$role` é `'worker'` (não é `null`). A rota `task` existe e aceita `GET`, então a rota é chamada.

---

## Passo 6: a task é buscada e a resposta volta

```php
// config/routes.php:18
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
| 4 | O header não veio, então `roleFor` recebe `null` e devolve `null` logo na primeira linha. Com uma chave errada, ela é comparada com as duas do `.env`, o hash dela é procurado na tabela `workers` e, como nada bate, devolve `null` no final |
| 5 | `$role` é `null`, então o `dispatch()` responde `401` ali mesmo |
| 6 | Não acontece. O controller e o banco nem ficam sabendo da requisição |

```json
{"error": "Invalid or missing API key"}
```

---

## Resumo de todas as situações

| Situação | `roleFor` devolve | Resposta |
|---|---|---|
| Chave de boss | `'boss'` | Segue para a rota |
| Chave de worker do `.env` | `'worker'` | Segue para a rota |
| Chave gerada para um worker no `POST /worker` | `'worker'` | Segue para a rota |
| Qualquer chave de worker em `POST /worker` | `'worker'` | `403` (só boss cria workers) |
| O banco falhou ao procurar a chave de worker | lança `PDOException` | `500` |
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
