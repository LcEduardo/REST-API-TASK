# Guia de uso da API de Tasks

Este guia é para quem vai **consumir** a API (um front-end, um app, um parceiro). Ele mostra o que pode ser chamado, o que enviar e o que esperar de volta.

## Visão geral

| | |
|---|---|
| **URL base** | `http://localhost:8000` (ambiente local; troque pelo endereço do servidor onde a API estiver publicada) |
| **Formato das respostas** | JSON (`Content-Type: application/json`) |
| **Formato do envio (POST e PUT)** | JSON (`Content-Type: application/json`). Form (`x-www-form-urlencoded` / `multipart/form-data`) não é aceito. |
| **Autenticação** | API key no header `X-API-Key` (veja [Autenticação](#autenticação)) |

### Endpoints

| Método | Rota | O que faz |
|---|---|---|
| `GET` | `/tasks` | Lista as tasks, com paginação |
| `GET` | `/task/{id}` | Busca uma task pelo id |
| `POST` | `/task` | Cria uma task |
| `GET` | `/worker/{id}` | Busca um funcionário pelo id |
| `POST` | `/worker` | Cadastra um funcionário e gera a API key dele (só boss) |
| `PUT` | `/worker/{id}` | Edita um funcionário |
| `GET` | `/boss/{id}` | Busca um chefe pelo id |
| `POST` | `/boss` | Cadastra um chefe |
| `PUT` | `/boss/{id}` | Edita um chefe |

As rotas ficam no singular quando tratam de um registro (`/task/{id}`, `POST /task`, `/worker/{id}`, `/boss/{id}`) e no plural só na listagem (`GET /tasks`). Só tasks podem ser listadas; workers e bosses ainda não, então `/workers` e `/bosses` devolvem `404`. Não existe rota para apagar. Só workers e bosses podem ser editados; tasks ainda não.

### Autenticação

Toda chamada precisa do header `X-API-Key` com uma chave válida. Existem duas chaves fixas, uma para chefes (boss) e uma para funcionários (worker); peça a sua para quem administra a API. Além delas, cada funcionário cadastrado por `POST /worker` recebe uma chave própria, que funciona como chave de worker.

```
curl http://localhost:8000/task/1 -H "X-API-Key: SUA_CHAVE"
```

Sem o header, com ele vazio ou com uma chave errada, a resposta é `401 {"error": "Invalid or missing API key"}`. Isso vale para qualquer rota, inclusive as que não existem: sem chave válida você recebe `401`, não `404`.

Por enquanto as duas chaves podem acessar quase todas as rotas. A exceção é `POST /worker`, que só a chave de boss pode chamar: com a chave de worker a resposta é `403 {"error": "Access Denied"}`. Em breve, a chave de worker também não vai poder criar tasks nem editar registros (`POST` e `PUT`).

Os exemplos de `curl` abaixo omitem o header para ficarem mais curtos. Adicione `-H "X-API-Key: SUA_CHAVE"` em todos.

### Enviando JSON

Mande o header `Content-Type: application/json` e um objeto JSON no corpo. Os campos são os mesmos do form.

```
curl -X POST http://localhost:8000/worker \
  -H "X-API-Key: SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"name": "Maria Oliveira", "email": "maria.oliveira@example.com"}'
```

Ids podem ir como número ou texto (`"boss_id": 1` ou `"boss_id": "1"`). Se o corpo estiver vazio ou não for um objeto JSON válido (JSON quebrado, um texto solto, um número), a resposta é `400 {"error": "Invalid JSON body"}`.

### Formato dos erros

Todo erro vem com o código HTTP correspondente e um corpo neste formato:

```json
{ "error": "Mensagem explicando o problema" }
```

---

## Tasks

### O objeto Task

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | inteiro | Identificador da task |
| `title` | texto | Título (até 150 caracteres) |
| `description` | texto ou `null` | Descrição livre |
| `status` | texto | `pending`, `in_progress` ou `done` |
| `created` | texto (data e hora) | Quando a task foi criada, ex.: `2026-10-29 14:05:12.481203` |
| `modified` | texto (data e hora) | Última alteração |
| `worker` | texto ou `null` | **Nome** do funcionário responsável, ou `null` se não houver |
| `boss` | texto ou `null` | **Nome** do chefe que criou a task |

> `worker` e `boss` vêm como nome, não como id.

### `GET /tasks`: listar as tasks (paginado)

Devolve as tasks em páginas, ordenadas por `id`. Cada item de `data` tem o mesmo formato do `GET /task/{id}`.

**Parâmetros da query string** (todos opcionais)

| Parâmetro | Padrão | Regra | Descrição |
|---|---|---|---|
| `page` | `1` | inteiro, a partir de 1 | Qual página buscar |
| `per_page` | `20` | inteiro, de 1 a 100 | Quantas tasks por página |

**Exemplo**

```
curl "http://localhost:8000/tasks?page=2&per_page=3"
```

**Resposta `200 OK`**

```json
{
  "data": [
    {
      "id": 4,
      "title": "Design dashboard",
      "description": "Create the layout for the main screen.",
      "status": "done",
      "created": "2026-10-29 14:05:12.481203",
      "modified": "2026-10-29 14:05:12.481203",
      "worker": "Diego Lima",
      "boss": "Henrique Dias"
    },
    ...
  ],
  "page": 2,
  "per_page": 3,
  "total": 8
}
```

| Campo | Descrição |
|---|---|
| `data` | As tasks desta página |
| `page` | A página devolvida |
| `per_page` | Quantas tasks por página |
| `total` | Quantas tasks existem ao todo. O número de páginas é `total / per_page`, arredondado para cima |

Uma página depois da última (ou uma lista sem nenhuma task) devolve `200` com `"data": []`, não `404`.

**Possíveis erros**

| Código | Quando | Corpo |
|---|---|---|
| `400` | `page` não é um inteiro maior que zero (ex.: `?page=0`, `?page=abc`) | `{"error": "The page must be a positive integer"}` |
| `400` | `per_page` não é um inteiro de 1 a 100 (ex.: `?per_page=500`) | `{"error": "The per_page must be an integer between 1 and 100"}` |
| `404` | Foi passado um id (`/tasks/1`). Para buscar uma task use `/task/{id}` | `{"error": "Route not found"}` |
| `500` | Falha no banco de dados | `{"error": "Database error"}` |

### `GET /task/{id}`: buscar uma task

Repare que é `task` no singular. `/tasks/{id}` devolve `404`.

**Exemplo**

```
curl http://localhost:8000/task/1
```

**Resposta `200 OK`**

```json
{
  "id": 1,
  "title": "Create login page",
  "description": "Build the login form with validation.",
  "status": "pending",
  "created": "2026-10-29 14:05:12.481203",
  "modified": "2026-10-29 14:05:12.481203",
  "worker": "Ana Silva",
  "boss": "Fernando Alves"
}
```

**Possíveis erros**

| Código | Quando | Corpo |
|---|---|---|
| `400` | O id não foi informado (`/task`) | `{"error": "ID not provided"}` |
| `400` | O id não é um número inteiro (ex.: `/task/abc`) | `{"error": "The task id isn't integer"}` |
| `404` | Não existe task com esse id | `{"error": "Task with id 99 not found"}` |
| `500` | Falha no banco de dados | `{"error": "Database error"}` |

### `POST /task`: criar uma task

Toda task nova começa com status `pending`.

**Campos do corpo**

| Campo | Obrigatório | Tipo | Descrição |
|---|---|---|---|
| `title` | **sim** | texto, até 150 caracteres | Título da task |
| `boss_id` | **sim** | inteiro | Id do chefe que está criando a task |
| `worker_id` | não | inteiro | Id do funcionário responsável. Se não enviar (ou enviar vazio), a task fica sem responsável |
| `description` | não | texto | Descrição livre |

**Exemplo com funcionário**

```
curl -X POST http://localhost:8000/task \
  -H "Content-Type: application/json" \
  -d '{"title": "Revisar contrato", "description": "Conferir as cláusulas novas", "boss_id": 1, "worker_id": 2}'
```

**Exemplo sem funcionário**

```
curl -X POST http://localhost:8000/task \
  -H "Content-Type: application/json" \
  -d '{"title": "Planejar sprint", "boss_id": 1}'
```

**Resposta `201 Created`**

Devolve a task criada, no mesmo formato do `GET /task/{id}`:

```json
{
  "id": 6,
  "title": "Planejar sprint",
  "description": null,
  "status": "pending",
  "created": "2026-10-29 15:20:44.102938",
  "modified": "2026-10-29 15:20:44.102938",
  "worker": null,
  "boss": "Fernando Alves"
}
```

Guarde o `id` da resposta se for consultar a task depois.

**Possíveis erros**

| Código | Quando | Corpo |
|---|---|---|
| `400` | `boss_id` não foi enviado ou não é um número inteiro | `{"error": "The boss_id isn't interger"}` |
| `400` | `worker_id` foi enviado mas não é um número inteiro | `{"error": "The worker_id isn't interger"}` |
| `404` | Não existe chefe com o `boss_id` informado | `{"error": "Boss with id 99 not found"}` |
| `404` | Não existe funcionário com o `worker_id` informado | `{"error": "Worker with id 99 not found"}` |
| `400` | `title` não foi enviado ou está vazio (só espaços também conta como vazio) | `{"error": "The title is required"}` |
| `400` | `title` ou `description` não é texto (ex.: `title[]=a`) | `{"error": "The title and description must be strings"}` |
| `500` | Falha no banco de dados | `{"error": "Database error"}` |

Os campos são verificados nesta ordem: `boss_id`, `worker_id` e depois `title`/`description`. Só o primeiro erro encontrado é devolvido. Por exemplo, se `boss_id` e `title` estiverem errados ao mesmo tempo, só o erro do `boss_id` volta.

Espaços no começo e no fim de `title` são removidos antes de gravar.

---

## Workers (funcionários)

### `GET /worker/{id}`: buscar um funcionário

**Exemplo**

```
curl http://localhost:8000/worker/1
```

**Resposta `200 OK`**

```json
{
  "id": 1,
  "name": "Ana Silva",
  "email": "ana.silva@example.com",
  "created": "2026-10-29 14:05:12.481203"
}
```

**Possíveis erros**

| Código | Quando | Corpo |
|---|---|---|
| `400` | O id não foi informado (`/worker`) | `{"error": "ID not provided"}` |
| `400` | O id não é um número inteiro | `{"error": "The worker_id isn't interger"}` |
| `404` | Não existe funcionário com esse id | `{"error": "Worker with id 99 not found"}` |
| `500` | Falha no banco de dados | `{"error": "Database error"}` |

### `POST /worker`: cadastrar um funcionário

Só a chave de **boss** pode chamar esta rota. Além de cadastrar o funcionário, ela gera uma API key nova para ele.

**Campos do corpo**

| Campo | Obrigatório | Tipo | Descrição |
|---|---|---|---|
| `name` | **sim** | texto, até 100 caracteres | Nome do funcionário |
| `email` | **sim** | texto, até 150 caracteres | Email do funcionário. Precisa ser um email válido e não pode já estar cadastrado |

Espaços no começo e no fim de `name` e `email` são removidos antes de gravar. O `email` é sempre gravado em minúsculo (`Maria@Example.com` vira `maria@example.com`), então o mesmo email com letras diferentes conta como já cadastrado.

**Exemplo**

```
curl -X POST http://localhost:8000/worker \
  -H "Content-Type: application/json" \
  -d '{"name": "Maria Oliveira", "email": "maria.oliveira@example.com"}'
```

**Resposta `201 Created`**

Devolve o funcionário criado, no mesmo formato do `GET /worker/{id}`, mais o campo `api_key`:

```json
{
  "id": 6,
  "name": "Maria Oliveira",
  "email": "maria.oliveira@example.com",
  "created": "2026-10-30 09:46:54.879862",
  "api_key": "3f9c0e7a1b...(64 caracteres)"
}
```

Guarde o `id` da resposta: ele é o `worker_id` usado em `POST /task`.

> **A `api_key` aparece só nesta resposta.** A API guarda apenas um hash (SHA-256) dela, então não é possível consultá-la depois: o `GET /worker/{id}` e o `PUT /worker/{id}` não a devolvem. Entregue a chave ao funcionário logo após o cadastro.

**Possíveis erros**

| Código | Quando | Corpo |
|---|---|---|
| `400` | `name` não foi enviado ou está vazio | `{"error": "The name is required"}` |
| `400` | `name` tem mais de 100 caracteres | `{"error": "The name must have at most 100 characters"}` |
| `400` | `email` não foi enviado ou não é um email válido | `{"error": "The email isn't valid"}` |
| `400` | `email` tem mais de 150 caracteres | `{"error": "The email must have at most 150 characters"}` |
| `403` | A chave enviada não é a de boss | `{"error": "Access Denied"}` |
| `409` | Já existe um funcionário com esse email | `{"error": "Email already registered"}` |
| `500` | Falha no banco de dados | `{"error": "Database error"}` |

Se `name` e `email` estiverem errados ao mesmo tempo, só o erro do `name` é devolvido. O `403` é verificado antes de tudo: com a chave de worker o corpo nem é lido.

### `PUT /worker/{id}`: editar um funcionário

O `PUT` substitui os dados do funcionário: envie **sempre `name` e `email`**, mesmo que só um deles mude. As regras são as mesmas do `POST /worker` (tamanhos, email válido, espaços removidos, email em minúsculo). O `id` e o `created` não mudam.

O corpo precisa ser JSON (veja [Enviando JSON](#enviando-json)).

**Exemplo**

```
curl -X PUT http://localhost:8000/worker/1 \
  -H "Content-Type: application/json" \
  -d '{"name": "Ana Silva Souza", "email": "ana.silva@example.com"}'
```

**Resposta `200 OK`**

Devolve o funcionário já atualizado, no mesmo formato do `GET /worker/{id}`:

```json
{
  "id": 1,
  "name": "Ana Silva Souza",
  "email": "ana.silva@example.com",
  "created": "2026-10-29 14:05:12.481203"
}
```

**Possíveis erros**

| Código | Quando | Corpo |
|---|---|---|
| `400` | O id não foi informado (`/worker`) | `{"error": "ID not provided"}` |
| `400` | O id não é um número inteiro | `{"error": "The worker_id isn't interger"}` |
| `400` | `name` ou `email` inválido (mesmos erros do `POST /worker`) | ex.: `{"error": "The name is required"}` |
| `404` | Não existe funcionário com esse id | `{"error": "Worker with id 99 not found"}` |
| `409` | O email já pertence a **outro** funcionário | `{"error": "Email already registered"}` |
| `500` | Falha no banco de dados | `{"error": "Database error"}` |

Os campos são verificados nesta ordem: id, `name`, `email` e só então se o funcionário existe. Ou seja, um `PUT /worker/99` com `name` vazio devolve `400`, não `404`.

---

## Bosses (chefes)

### `GET /boss/{id}`: buscar um chefe

**Exemplo**

```
curl http://localhost:8000/boss/1
```

**Resposta `200 OK`**

```json
{
  "id": 1,
  "name": "Fernando Alves",
  "email": "fernando.alves@example.com",
  "created": "2026-10-29 14:05:12.481203"
}
```

**Possíveis erros**

| Código | Quando | Corpo |
|---|---|---|
| `400` | O id não foi informado (`/boss`) | `{"error": "ID not provided"}` |
| `400` | O id não é um número inteiro | `{"error": "The boss_id isn't interger"}` |
| `404` | Não existe chefe com esse id | `{"error": "Boss with id 99 not found"}` |
| `500` | Falha no banco de dados | `{"error": "Database error"}` |

### `POST /boss`: cadastrar um chefe

Funciona igual ao `POST /worker`: mesmos campos (`name` até 100 caracteres, `email` válido até 150), mesmo tratamento de espaços e email em minúsculo, e os mesmos erros (`400`, `409`, `500`). Os emails de chefes e de funcionários são independentes: o mesmo email pode estar nas duas tabelas.

**Exemplo**

```
curl -X POST http://localhost:8000/boss \
  -H "Content-Type: application/json" \
  -d '{"name": "Chefe Teste", "email": "chefe.teste@example.com"}'
```

**Resposta `201 Created`**

Devolve o chefe criado, no mesmo formato do `GET /boss/{id}`:

```json
{
  "id": 6,
  "name": "Chefe Teste",
  "email": "chefe.teste@example.com",
  "created": "2026-10-31 11:20:34.442859"
}
```

Guarde o `id` da resposta: ele é o `boss_id` usado em `POST /task`.

### `PUT /boss/{id}`: editar um chefe

Funciona igual ao `PUT /worker/{id}`: envie sempre `name` e `email`, com as mesmas regras e os mesmos erros (`400`, `404`, `409`, `500`). A resposta `200 OK` traz o chefe atualizado, no formato do `GET /boss/{id}`. Nos erros de id a mensagem é `The boss_id isn't interger` e `Boss with id 99 not found`.

**Exemplo**

```
curl -X PUT http://localhost:8000/boss/1 \
  -H "Content-Type: application/json" \
  -d '{"name": "Fernando Alves", "email": "fernando.alves@example.com"}'
```

---

## Erros gerais

Valem para qualquer chamada:

| Código | Quando | Corpo |
|---|---|---|
| `401` | Faltou o header `X-API-Key`, ele veio vazio ou a chave é inválida. É checado antes da rota | `{"error": "Invalid or missing API key"}` |
| `404` | A rota não existe (ex.: `/projetos`, `/register/worker`) | `{"error": "Route not found"}` |
| `405` | A rota existe, mas o método não é suportado (ex.: `DELETE /task/1`, `PUT /task/1`, `DELETE /boss/1`). O cabeçalho `Allow` diz quais métodos a rota aceita | `{"error": "Method not allowed"}` |
| `500` | A API não conseguiu se conectar ao banco | `{"error": "Database connection failed", "code": ...}` |

## Resumo dos códigos HTTP

| Código | Significado para o cliente |
|---|---|
| `200` | Deu certo. O registro (buscado ou editado), ou a lista de registros, está no corpo |
| `201` | O registro (task ou funcionário) foi criado. Ele está no corpo |
| `400` | O pedido está incompleto ou com formato errado. Corrija e envie de novo |
| `401` | Sem API key ou com uma chave inválida. Envie o header `X-API-Key` com a sua chave |
| `404` | O registro ou a rota não existe |
| `409` | Conflito com um registro que já existe (ex.: email já cadastrado). Não adianta reenviar igual |
| `405` | Esse método não é aceito nessa rota |
| `500` | Problema no servidor. Não é culpa do cliente; tente de novo mais tarde |

## Limitações atuais

- Um `title` com mais de 150 caracteres devolve `500 Database error` em vez de `400`.
- Um id muito grande (acima de 2147483647), em `GET /task/{id}`, `GET /worker/{id}`, `GET /boss/{id}` em `PUT /worker/{id}`, `PUT /boss/{id}` ou no `boss_id`/`worker_id` de `POST /task`, devolve `500 Database error` em vez de `400`.
- O corpo precisa ser JSON. Form (x-www-form-urlencoded ou multipart) devolve `400 Invalid JSON body`.
- Ainda não há rotas para listar workers e bosses, para apagar registros, nem para editar tasks.
- As chaves de boss e de worker ainda têm as mesmas permissões: as duas acessam todas as rotas.
