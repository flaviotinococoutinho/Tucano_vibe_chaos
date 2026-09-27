# catalog

Serviço de catálogo da Tucano: produtos, preços, peso e dimensões. É um subdomínio de suporte com muita leitura e pouca escrita, então a leitura segue cache-aside, com o Redis na frente do MySQL 8.4.

Escrevi o catálogo em Lumen 11 de propósito. Ele faz o papel de serviço legado dentro de uma arquitetura moderna ([ADR 0009](../../docs/adr/0009-lumen-legacy-service.md)), e montei o esqueleto à mão porque o repositório `laravel/lumen` foi arquivado na versão 10.

## Lumen 11 é EOL

A documentação oficial não recomenda mais o Lumen para projetos novos, e a versão 11.2 não recebe correção de segurança desde março de 2026. Por isso o serviço fica preso ao PHP 8.3 de forma explícita: o `composer.json` exige `~8.3.0` e a imagem parte de `chaos-playground/php-base:8.3`. É dívida técnica registrada no ADR, e a migração para Laravel fica como exercício.

## Por que camadas e não hexágono

O catálogo é quase um CRUD com cache. Ports e adapters aqui seriam cerimônia sem retorno, então uso camadas simples: o controller cuida do HTTP, o service guarda as regras e o cache-aside, e o repository fala com o MySQL ([ADR 0003](../../docs/adr/0003-package-by-feature-hexagonal.md)). As rotas de produto vão seguir essa ordem em `app/Http/Controllers`, `app/Services` e `app/Repositories`.

| Pasta | O que tem |
|---|---|
| `app/Http` | `HealthController`, o middleware `CorrelationId` e o `ProblemDetails` |
| `app/Exceptions/Handler.php` | transforma qualquer erro em problem details |
| `app/Health` | checks de MySQL e Redis do readiness |
| `app/Logging/LogContext.php` | campos que entram em toda linha de log |
| `app/Providers/PlatformServiceProvider.php` | relógio, feature flags, contexto de log e health checks |
| `config/` | só o que o serviço usa: `app`, `cache`, `database`, `logging` e `platform` |
| `database/` | migrations com DDL explícito e seeders |
| `artisan` | console do Lumen: `migrate`, `db:seed` e companhia |
| `routes/web.php` | rotas |

## O que muda em relação ao Laravel

- Facades e Eloquent ficam desligados, como o Lumen vem. Tudo chega por injeção de dependência, e o banco é acessado pelo `DatabaseManager`. Nos comandos de console o Lumen liga as facades, sem os aliases globais, então migrations e seeders importam a `DB` que usam.
- As rotas usam `Controller@method` dentro do namespace definido em `bootstrap/app.php`. A sintaxe de array do Laravel não funciona aqui.
- O Lumen faz `bindTo` em toda closure de rota, então uma closure de rota não pode ser `static`.
- Não existe o `Context` do Laravel 11. O `LogContext` é um processor do Monolog que grava `service` e `correlation_id` em `extra`, o mesmo lugar que o commerce usa, e as linhas de log dos dois serviços ficam com o mesmo formato.
- O Lumen só carrega um arquivo de config quando algum componente pede. O `bootstrap/app.php` carrega todos logo na subida, porque o Redis depende do `database.php` e os testes precisam sobrescrever qualquer valor.
- O Redis não vem com o framework: o serviço depende de `illuminate/redis` e registra o `RedisServiceProvider`.
- Não existe `config:cache` nem `route:cache`. O container sobe direto no `php-fpm`.
- O roteador do Lumen lança 404 e 405 sem mensagem, então o `detail` desses erros repete o `title`.
- Cada aplicação do Lumen registra um error handler e um exception handler. O `Tests\TestCase` remove os dois no `tearDown`, senão o PHPUnit 12 marca todos os testes como risky.

## Banco

O schema e os seeds ficam em `database/`. O job `catalog-migrate` do compose roda `php artisan migrate --force --seed` antes da API subir, e os seeds não sobrescrevem o que mudou depois. Os tipos e as diferenças para o PostgreSQL estão no [modelo de dados](../../docs/architecture/data-model.md#catalog-mysql-banco-catalog).

## Endpoints

| Rota | Pelo Kong | O que faz |
|---|---|---|
| `GET /health/live` | `/api/catalog/health/live` | o processo está de pé |
| `GET /health/ready` | `/api/catalog/health/ready` | MySQL e Redis respondem, com a latência de cada um |

Todo erro sai como `application/problem+json` (RFC 9457) com o `correlationId` do request. Erros de domínio viram status pela categoria (`NotFound` 404, `Conflict` 409, `InvalidInput` 422, `Forbidden` 403, `Unavailable` 503) e não vão para o log de erro, porque são respostas esperadas, não incidentes. Um erro inesperado responde 500 sem detalhes internos enquanto `APP_DEBUG` for `false`.

## Rodando

```bash
make up                  # sobe a stack com o catalog
make check s=catalog     # Pint, PHPStan (nível 8) e PHPUnit no PHP 8.3, contra a stack
make logs s=catalog
```

Os testes do grupo `integration` precisam do MySQL e do Redis de verdade: o `make check` roda dentro da rede da stack, e a CI sobe os dois como service containers.

A configuração vem só de variáveis de ambiente, e os valores padrão já servem para a stack:

| Variável | Padrão |
|---|---|
| `APP_NAME` | `catalog` |
| `APP_ENV` | `production` (a stack passa `local`) |
| `APP_DEBUG` | `false` |
| `LOG_CHANNEL` e `LOG_LEVEL` | `stderr` e `debug` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` e `DB_PORT` | `toxiproxy` e `13306` |
| `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` | `catalog` |
| `REDIS_HOST` e `REDIS_PORT` | `toxiproxy` e `16379` |
| `CACHE_STORE` | `redis` |
| `FLAGD_HOST` e `FLAGD_PORT` | `toxiproxy` e `18013` |
| `FLAGS_DRIVER` | `flagd` (`memory` nos testes) |

## Decisões do runtime

- **Flags**: `Flagd::connect` com cache em APCu no FPM e em memória no CLI. Ambiente desconhecido conta como produção, e o `ProductionGuard` mantém as flags de caos desligadas.
- **Dependências passam pelo Toxiproxy** (`DB_HOST=toxiproxy`, `DB_PORT=13306`), para os experimentos de caos funcionarem sem mudar nada no serviço.
- **Nada vai para o disco**: o log sai no stderr, inclusive o logger de emergência do Laravel, e o cache fica no Redis. Por isso o serviço não tem a pasta `storage/`.
