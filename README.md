# API de Tasks

API simples em PHP puro com PostgreSQL para consultar tarefas (`tasks`) e funcionários (`workers`).

> Vai consumir a API? Veja o [Guia de uso da API](API.md), com as rotas, os campos e os erros.

## Requisitos

| Ferramenta | Versão | Observação |
|---|---|---|
| PHP | **8.3 ou mais nova** (testado com 8.3 e 8.4) | Precisa das extensões `pdo_pgsql` e `pgsql` ativas |
| PostgreSQL | qualquer versão atual | Banco `task` |
| Composer | 2.x | Instala o autoload e o Phinx |
| Phinx | 0.16 | Ferramenta de migrations, instalada pelo Composer |
| phpdotenv | 5.6.1 ou mais nova (`^5.6`) | Lê o `.env`, instalado pelo Composer. Versões anteriores à 5.6.1 geram aviso de *Deprecated* no PHP 8.4 |
| PHPUnit | 12 | Testes automatizados, instalado pelo Composer (só em desenvolvimento) |

Para conferir a versão do PHP e as extensões:

```
php -v
php -m
```

> **Por que 8.3?** O `composer.json` tem `"config": { "platform": { "php": "8.3.0" } }`. Isso faz o Composer escolher só pacotes que funcionam no PHP 8.3, mesmo que ele esteja rodando num PHP mais novo (ex.: 8.4 do Herd). Sem isso, ele poderia instalar pacotes que exigem 8.4 e o projeto quebraria em quem usa 8.3. Não remova essa configuração sem combinar com o time.

## Estrutura

```
.
├── index.php                  # Ponto de entrada: monta as peças, confere a API key e chama o dispatch
├── composer.json              # Configuração do autoload PSR-4 e dependências (Phinx, phpdotenv)
├── .env.example               # Modelo das variáveis de ambiente (copie para .env)
├── .env                       # Suas credenciais do banco (NÃO vai para o git)
├── phinx.php                  # Configuração das migrations (conexão com o banco)
├── phpunit.xml                # Configuração dos testes
├── docs/                      # Explicações detalhadas de partes do código
├── tests/                     # namespace Tests
│   └── Unit/                  # Testes de unidade (não usam o banco)
├── db/
│   ├── migrations/            # Migrations: cada arquivo é uma mudança no banco
│   └── seeds/                 # Seeds: dados de exemplo
├── config/
│   ├── database.php           # Conexão com o banco via PDO
│   └── routes.php             # Tabela de rotas: recurso => [método => função]
└── src/
    ├── Auth/                  # namespace App\Auth
    │   └── ApiKeyAuth.php         # Diz se a chave do header X-API-Key é de boss, de worker ou inválida
    ├── Helpers/               # Funções soltas, carregadas pelo "files" do composer.json
    │   ├── http.php               # respond, error, routeId, requireId, requireRole, withJsonBody
    │   └── routing.php            # parseSegments (URL -> pedaços) e dispatch (decide a resposta)
    ├── Controller/            # namespace App\Controller
    │   ├── ControllerBoss.php     # Monta a resposta de bosses (corpo + status HTTP)
    │   ├── ControllerTask.php     # Monta a resposta de tasks (corpo + status HTTP)
    │   └── ControllerWorker.php   # Monta a resposta de workers (corpo + status HTTP)
    └── Model/                 # namespace App\Model
        ├── Model.php              # Classe base com getById, create, update e count genéricos
        ├── Boss.php               # Consultas SQL de bosses
        ├── Task.php               # Consultas SQL de tasks
        └── Worker.php             # Consultas SQL de workers
```

## Autoload (Composer PSR-4)

As classes são carregadas automaticamente pelo Composer, sem `require` manual. O `composer.json` liga cada namespace a uma pasta:

| Namespace | Pasta |
|---|---|
| `App\Auth\` | `src/Auth/` |
| `App\Controller\` | `src/Controller/` |
| `App\Model\` | `src/Model/` |

Para uma classe nova ser encontrada:

- o arquivo deve ter **o mesmo nome da classe** (`class Task` → `Task.php`);
- o arquivo deve declarar o namespace da pasta (`namespace App\Model;`);
- classes nativas do PHP usadas dentro do namespace precisam de `use` (`use PDO;`, `use Exception;`).

As funções soltas de `src/Helpers/` não têm namespace nem classe, então o PSR-4 não as encontra. Elas ficam listadas em `autoload.files` no `composer.json` e são carregadas em toda requisição (e nos testes).

Se você mudar o mapeamento ou a lista de `files` no `composer.json`, rode `composer dump-autoload`.

## Como rodar

1. Na raiz do projeto, instale o autoload (gera a pasta `vendor/`, que não vai para o git):

   ```
   composer install
   ```

2. Crie o banco `task` no PostgreSQL. Copie `.env.example` para `.env` e preencha suas credenciais:

   ```
   cp .env.example .env
   ```

   O `.env` está no `.gitignore`: a senha nunca vai para o repositório. Em produção, você pode definir as variáveis (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) direto no servidor em vez de usar o arquivo. Se faltar alguma, a API responde 500 e o Phinx mostra qual variável está faltando.

   Preencha também as API keys (`API_SECRET_KEY_BOSS` e `API_SECRET_KEY_WORKER`). Gere cada uma com `php -r "echo bin2hex(random_bytes(32));"`. Se uma delas ficar vazia, ninguém consegue entrar com ela: toda chamada responde 401. Para usar JWT (`POST /token`), preencha `JWT_SECRET` (mínimo 32 bytes, gerado do mesmo jeito) e, se quiser, `JWT_TTL` (validade em segundos, padrão 3600).
3. Crie as tabelas e insira os dados de exemplo:

   ```
   php vendor/bin/phinx migrate
   php vendor/bin/phinx seed:run
   ```
4. Na raiz do projeto, suba o servidor embutido do PHP:

   ```
   php -S localhost:8000
   ```

5. Teste (mande o header `X-API-Key` com uma das chaves do `.env`):
   - `GET http://localhost:8000/tasks?page=1&per_page=20`
   - `GET http://localhost:8000/task/1`
   - `GET http://localhost:8000/worker/1`

## Migrations (Phinx)

| Comando | O que faz |
|---|---|
| `php vendor/bin/phinx create NomeDaMudanca` | Cria um arquivo de migration novo em `db/migrations/` |
| `php vendor/bin/phinx migrate` | Aplica as migrations pendentes |
| `php vendor/bin/phinx rollback` | Desfaz a última migration |
| `php vendor/bin/phinx status` | Mostra quais migrations já rodaram |
| `php vendor/bin/phinx seed:run` | Insere os dados de exemplo |

Como funciona:

- A API (`config/database.php`) e as migrations (`phinx.php`) leem as credenciais do mesmo `.env` (via `vlucas/phpdotenv`, que coloca as variáveis em `$_ENV`). Para mudar as credenciais, mude só o `.env`.
- O Phinx guarda na tabela `phinxlog` quais migrations já rodaram. Por isso `migrate` só aplica as que ainda faltam.
- A primeira migration (`CreateInitialTables`) cria as tabelas `workers`, `bosses` e `tasks`.

Regras para o dia a dia:

- **Toda mudança no banco vira uma migration nova** (`phinx create`). Não altere as tabelas direto no banco.
- **Não edite uma migration que já rodou** em algum banco. Para corrigir algo, crie outra migration.
- **Banco que já tinha as tabelas** (criadas antes das migrations): rode `php vendor/bin/phinx migrate --fake` uma vez. Isso marca a migration inicial como aplicada sem tentar criar as tabelas de novo.
- **Rode `seed:run` só em banco vazio.** Os e-mails são únicos, então rodar de novo dá erro.

## Testes (PHPUnit) e TDD

Para rodar todos os testes:

```
composer test
```

Os testes ficam em `tests/`, espelhando as pastas do código: o teste de `src/Controller/ControllerWorker.php` fica em `tests/Unit/Controller/ControllerWorkerTest.php`.

- O nome do arquivo e da classe termina em `Test` e a classe estende `PHPUnit\Framework\TestCase`.
- Cada método de teste começa com `test` e descreve o comportamento (`testGetWorkerReturns400WhenIdIsNotInteger`).
- Testes de unidade **não usam o banco**: o model é trocado por um falso com `createStub` (só devolve valores) ou `createMock` (também confere se foi chamado).

Fluxo do TDD, para cada comportamento novo:

1. **Vermelho:** escreva um teste para o comportamento que ainda não existe e rode `composer test`. Ele deve falhar.
2. **Verde:** escreva o mínimo de código para o teste passar.
3. **Refatorar:** melhore o código sem mudar o comportamento, rodando os testes de novo para garantir que continuam passando.

## Papel de cada camada

- **Root (`index.php`)**: é a porta de entrada, mas só junta as peças. Monta models e controllers, confere a API key (veja [Autorização](#autorização-api-keys)), carrega a tabela de rotas de `config/routes.php`, separa a URL em segmentos com `parseSegments` (`/task/1` → recurso `task`, id `1`) e pede ao `dispatch` a resposta (401, 404, 405 ou a rota). No fim, `respond` aplica o status HTTP e devolve o JSON.
- **Helpers (`src/Helpers/`)** e **rotas (`config/routes.php`)**: funções pequenas que conferem id, papel e corpo JSON antes de chamar o controller, e a tabela que liga cada recurso e método ao controller certo. Explicação completa, passo a passo com `GET /task/{id}` e `POST /worker`: [docs/roteamento-e-helpers.md](docs/roteamento-e-helpers.md).
- **Controller (`ControllerTask`, `ControllerWorker`)**: recebe o model pronto, chama a consulta e transforma o resultado em resposta (`status` + `body`). É quem trata as exceções vindas do model e decide o código HTTP (200, 404, 500).
- **Model (`Task`, `Worker`)**: só conversa com o banco, usando *prepared statements*. Não sabe nada de HTTP: se não encontra o registro, lança uma exceção e deixa o controller decidir o que responder.

## Autorização (API keys)

Toda requisição precisa do header `X-API-Key` com a chave de boss ou de worker (as duas ficam no `.env`), ou com a chave própria gerada para um worker no `POST /worker`. Sem chave válida, a API responde `401` antes mesmo de olhar a rota. Quem confere a chave é `src/Auth/ApiKeyAuth.php` (classe de apoio), chamado no começo do roteamento em `index.php`.

Explicação completa do código, passo a passo com o exemplo `GET /task/{id}`: [docs/autorizacao-api-keys.md](docs/autorizacao-api-keys.md).

## Status HTTP usados

| Código | Significado | Quando |
|---|---|---|
| 200 | OK | Registro encontrado, ou lista de registros (`GET /tasks`, mesmo vazia) |
| 400 | Bad Request | O id não foi informado na URL (ex.: `GET /task`), ou `page`/`per_page` inválido em `GET /tasks` |
| 401 | Unauthorized | Faltou o header `X-API-Key`, ele veio vazio ou a chave é inválida |
| 404 | Not Found | O id não corresponde a nenhum registro, ou a rota não existe |
| 405 | Method Not Allowed | Método HTTP não suportado em `/task`, `/tasks`, `/worker` ou `/boss` (ex.: `DELETE`) |
| 500 | Internal Server Error | Falha na conexão ou na consulta ao banco |
