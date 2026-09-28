# catalog

Serviço de catálogo da Tucano: produtos, preços, peso e dimensões. É um subdomínio de suporte com muita leitura e pouca escrita, então a leitura segue cache-aside, com o Redis na frente do MySQL 8.4, e cada mudança de um produto publicado vira um snapshot no Kafka.

Escrevi o catálogo em Lumen 11 de propósito. Ele faz o papel de serviço legado dentro de uma arquitetura moderna ([ADR 0009](../../docs/adr/0009-lumen-legacy-service.md)), e montei o esqueleto à mão porque o repositório `laravel/lumen` foi arquivado na versão 10.

## Lumen 11 é EOL

A documentação oficial não recomenda mais o Lumen para projetos novos, e a versão 11.2 não recebe correção de segurança desde março de 2026. Por isso o serviço fica preso ao PHP 8.3 de forma explícita: o `composer.json` exige `~8.3.0` e a imagem parte de `chaos-playground/php-base:8.3`. É dívida técnica registrada no ADR, e a migração para Laravel fica como exercício.

## Por que camadas e não hexágono

O catálogo é quase um CRUD com cache. Ports e adapters aqui seriam cerimônia sem retorno, então uso camadas simples: o controller cuida do HTTP, o service guarda as regras, o cache-aside e a publicação, e o repository fala com o MySQL ([ADR 0003](../../docs/adr/0003-package-by-feature-hexagonal.md)).

| Pasta | O que tem |
|---|---|
| `app/Http` | `ProductController`, `HealthController`, o middleware `CorrelationId` e o `ProblemDetails` |
| `app/Services` | `ProductService` (regras, concorrência otimista e dual write), `ProductCache` (cache-aside com lock), `ProductPublisher` e `ProductSnapshot` (o evento) |
| `app/Repositories` | o SQL de produtos e categorias |
| `app/Models` | `Product`, `ProductStatus` e os value objects: classes `readonly`, sem Eloquent |
| `app/Exceptions` | o `Handler`, que transforma qualquer erro em problem details, e os erros de domínio (`ProductNotFound`, `DuplicateSku`, `TransitionNotAllowed`, `StaleVersion` e `UnknownCategory`) |
| `app/Console/Commands` | o `catalog:republish` |
| `app/Messaging` | `LazyProducer`, que só cria o producer do Kafka na primeira mensagem |
| `app/Health` | checks de MySQL e Redis do readiness |
| `app/Logging/LogContext.php` | campos que entram em toda linha de log |
| `app/Providers/PlatformServiceProvider.php` | relógio, feature flags, producer do Kafka, locks do cache, contexto de log e health checks |
| `config/` | só o que o serviço usa: `app`, `cache`, `database`, `logging` e `platform` |
| `database/` | migrations com DDL explícito e seeders |
| `artisan` | console do Lumen: `migrate`, `db:seed`, `catalog:republish` e companhia |
| `routes/web.php` | rotas |

## O que muda em relação ao Laravel

- Facades e Eloquent ficam desligados, como o Lumen vem. Tudo chega por injeção de dependência, e o banco é acessado pelo `DatabaseManager`. Nos comandos de console o Lumen liga as facades, sem os aliases globais, então migrations e seeders importam a `DB` que usam.
- As rotas usam `Controller@method` dentro do namespace definido em `bootstrap/app.php`. A sintaxe de array do Laravel não funciona aqui.
- O Lumen faz `bindTo` em toda closure de rota, então uma closure de rota não pode ser `static`.
- Não existe o `Context` do Laravel 11. O `LogContext` é um processor do Monolog que grava `service` e `correlation_id` em `extra`, o mesmo lugar que o commerce usa, e as linhas de log dos dois serviços ficam com o mesmo formato. Ele também faz o papel do `Context::get()`: o evento publicado durante um request lê o correlation id dali.
- O Lumen só carrega um arquivo de config quando algum componente pede. O `bootstrap/app.php` carrega todos logo na subida, porque o Redis depende do `database.php` e os testes precisam sobrescrever qualquer valor.
- O Redis não vem com o framework: o serviço depende de `illuminate/redis` e registra o `RedisServiceProvider`.
- Não existe `config:cache` nem `route:cache`. O container sobe direto no `php-fpm`.
- O roteador do Lumen lança 404 e 405 sem mensagem, então o `detail` desses erros repete o `title`.
- As mensagens de validação vêm do arquivo em inglês do Lumen, mais antigo que o do Laravel: `The page must be at least 1.`, sem o `field` no meio.
- Cada aplicação do Lumen registra um error handler e um exception handler. O `Tests\TestCase` remove os dois no `tearDown`, senão o PHPUnit 12 marca todos os testes como risky.

## Banco

O schema e os seeds ficam em `database/`. O job `catalog-migrate` do compose roda `php artisan migrate --force --seed` antes da API subir, e os seeds não sobrescrevem o que mudou depois. Os tipos e as diferenças para o PostgreSQL estão no [modelo de dados](../../docs/architecture/data-model.md#catalog-mysql-banco-catalog).

## Produtos

O estado do produto é um backed enum, `ProductStatus`, com a tabela de transições num `match` exaustivo ([ADR 0011](../../docs/adr/0011-state-machines-without-flags.md)). Todo produto nasce rascunho.

| De | Para | Rota |
|---|---|---|
| `draft` | `active` | `POST /v1/products/{sku}/activate` |
| `active` | `discontinued` | `POST /v1/products/{sku}/discontinue` |
| `discontinued` | `active` | `POST /v1/products/{sku}/activate` |

Qualquer outra transição responde 409. Um rascunho nunca sai do catálogo: não aparece na listagem, o `GET` responde 404 e ele não vai para o Kafka. Um produto descontinuado sai da listagem, mas o `GET` ainda o mostra, porque pedidos antigos continuam apontando para ele.

## Endpoints

| Rota | Pelo Kong | O que faz |
|---|---|---|
| `GET /health/live` | `/api/catalog/health/live` | o processo está de pé |
| `GET /health/ready` | `/api/catalog/health/ready` | MySQL e Redis respondem, com a latência de cada um |
| `GET /v1/products` | `/api/catalog/v1/products` | produtos ativos por nome, 20 por página; aceita `category` (slug) e `page` |
| `GET /v1/products/{sku}` | `/api/catalog/v1/products/{sku}` | um produto ativo ou descontinuado, lido pelo cache, com `ETag` |
| `POST /v1/products` | `/api/catalog/v1/products` | cria um rascunho: 201 com `Location`, ou 409 se a SKU já existe |
| `PATCH /v1/products/{sku}` | `/api/catalog/v1/products/{sku}` | muda nome, preço, categoria, peso ou dimensões; aceita `If-Match` |
| `POST /v1/products/{sku}/activate` | `/api/catalog/v1/products/{sku}/activate` | coloca à venda um rascunho ou um produto descontinuado |
| `POST /v1/products/{sku}/discontinue` | `/api/catalog/v1/products/{sku}/discontinue` | tira de venda |

O JSON é em camelCase, e dinheiro sai em centavos com a moeda:

```json
{
  "id": "01999a3c-6f12-7d43-b2a8-4c1e5f6a7b8c",
  "sku": "BOOK-DDD-001",
  "name": "Domain-Driven Design",
  "status": "active",
  "category": "books",
  "price": { "amount": 18990, "currency": "BRL" },
  "weightGrams": 1100,
  "dimensions": { "lengthMm": 240, "widthMm": 170, "heightMm": 40 },
  "version": 1,
  "updatedAt": "2026-09-27T12:00:04.567Z"
}
```

A listagem devolve `{"data": [...], "page": 1, "perPage": 20, "total": 16}`.

- Uma SKU fora do formato do `CHECK` (`^[A-Z0-9][A-Z0-9-]{2,31}$`) nem chega ao controller: o roteador responde 404, sem consulta e sem chave nova no cache.
- Validação responde 422 com `errors` por campo. Categoria que não existe também é 422, no `POST`, no `PATCH` e no filtro da listagem.
- O `POST` não pede `Idempotency-Key`: a SKU já é a chave natural, e um retry recebe 409 e pode ler o produto com `GET`.
- Um `PATCH` que não muda nada devolve o produto como está, sem versão nova e sem evento.

Todo erro sai como `application/problem+json` (RFC 9457) com o `correlationId` do request. Erros de domínio viram status pela categoria (`NotFound` 404, `Conflict` 409, `InvalidInput` 422, `Forbidden` 403, `Unavailable` 503) e não vão para o log de erro, porque são respostas esperadas, não incidentes. Um erro inesperado responde 500 sem detalhes internos enquanto `APP_DEBUG` for `false`.

## Concorrência otimista

Cada produto tem um `version`, que sobe a cada mudança, e toda resposta de um produto traz `ETag: "<version>"`. Quem manda `If-Match: "3"` num `PATCH` só altera o produto se ele ainda estiver na versão 3. Se não estiver, recebe 409 e relê antes de tentar de novo. `If-Match: *` aceita qualquer versão, e um valor fora do formato é 400.

Mesmo sem `If-Match`, o `UPDATE` só grava com `WHERE version = ?`, usando a versão lida no começo do request. Duas escritas simultâneas nunca se sobrescrevem: a segunda afeta zero linhas e recebe 409. A RFC 9110 manda 412 quando o `If-Match` falha. Uso 409 porque é o mesmo conflito de versão do `UPDATE`, e o `ProblemDetails` mapeia erro de domínio por categoria.

## Cache-aside e stampede

O `GET /v1/products/{sku}` lê primeiro o Redis (chave `product:v1:<sku>` no store `cache`). Na falta, lê o MySQL e grava a resposta no Redis.

- **TTL de 300 s com jitter de 10%**, entre 270 e 330 s. Chaves gravadas juntas, depois de um deploy ou de um flush, expiram em momentos diferentes, e o MySQL não recebe todas as releituras no mesmo segundo.
- **Cache negativo de 30 s**: SKU desconhecida e rascunho também ficam no cache, marcados como `missing`. Uma enxurrada de 404 não chega ao MySQL, e o TTL curto não esconde por muito tempo um produto que apareceu por fora do serviço.
- **Proteção contra stampede**: quando a chave some, só o request que pega o lock `product-rebuild:<sku>` (um `SET NX EX 5` no Redis) lê o MySQL. Os outros esperam até 500 ms, relendo o cache a cada 50 ms, e só vão ao MySQL se a resposta não chegar. O lock expira sozinho se o processo que o segura morrer.
- **Invalidação**: toda escrita que dá certo apaga a chave depois do commit, inclusive a criação, que derruba um `missing` antigo. Ainda existe a corrida clássica do cache-aside, em que um leitor lento grava o valor antigo logo depois da invalidação. O TTL limita essa janela a cinco minutos.
- **O Redis é otimização, não dependência**: se ele falha, a leitura vai direto ao MySQL e a escrita segue, com um warning no log.

Hit e miss saem no log em `debug` (`product cache hit` e `product cache miss`). A stack roda com `LOG_LEVEL=info`, então para vê-los é preciso subir o catalog com `LOG_LEVEL=debug`.

## Dual write e o catalog:republish

Depois do commit no MySQL, toda mudança de um produto publicado (`PATCH`, `activate` e `discontinue`) manda um CloudEvent `tucano.catalog.product.snapshot` para o tópico compactado `catalog.products.v1`, com a chave igual ao id do produto e o estado completo em `data`. O schema do `data` está em [`contracts/events/catalog.product.snapshot.schema.json`](../../contracts/events/catalog.product.snapshot.schema.json), e um teste de contrato valida o evento produzido contra ele e contra o envelope.

Aqui não existe outbox, de propósito ([ADR 0008](../../docs/adr/0008-transactional-outbox.md)). Se o Kafka falha depois do commit, a escrita continua valendo, porque o MySQL é a fonte da verdade: o request responde normalmente e o log ganha um warning `product snapshot not published` com `product_id`, `sku` e `version`. Esse é o preço do dual write: até alguém agir, o tópico fica atrás do banco, e o commerce e o logistics continuam com a versão anterior do produto.

O `catalog:republish` fecha essa janela. Ele lê do MySQL todos os produtos ativos e descontinuados, em lotes de 100, e publica o estado atual de cada um:

```bash
docker compose exec catalog php artisan catalog:republish                     # todos
docker compose exec catalog php artisan catalog:republish --sku=BOOK-DDD-001  # só um
```

Repetir é seguro: o tópico compactado guarda a última mensagem de cada chave, e o consumidor fica com a versão mais alta que já viu. Se o Kafka falha no meio, o comando sai com código 1, e basta rodar de novo. Um rascunho nunca é publicado, nem pelo comando.

O `KAFKA_PRODUCER_MESSAGE_TIMEOUT_MS` limita quanto uma escrita espera pelo Kafka. Com o broker fora, um `PATCH` demora esse tempo e responde 200.

## Rodando

```bash
make up                  # sobe a stack com o catalog
make check s=catalog     # Pint, PHPStan (nível 8) e PHPUnit no PHP 8.3, contra a stack
make logs s=catalog
```

| Suíte | O que cobre |
|---|---|
| `tests/Unit` | máquina de estados, `Product`, mapeamento do snapshot e jitter, sem framework |
| `tests/Feature` | as rotas por HTTP, com MySQL e Redis de verdade |
| `tests/Integration` | repository, lock do cache e constraints do schema |
| `tests/Contract` | o evento publicado contra `contracts/events` |

Os testes do grupo `integration` precisam do MySQL e do Redis de verdade: o `make check` roda dentro da rede da stack, e a CI sobe os dois como service containers. Cada teste roda numa transação desfeita no fim e usa um prefixo próprio no Redis, então nada vaza para a stack. O Kafka fica de fora: o `InMemoryProducer` guarda o que seria publicado e também finge um broker fora do ar.

A configuração vem só de variáveis de ambiente, e os valores padrão já servem para a stack. A lista completa, com o que cada uma faz, está na [referência de configuração](../../docs/operations/configuration.md#catalog). As que mais mexo nos experimentos são o TTL do cache (`CATALOG_CACHE_TTL_SECONDS`) e a espera pelo Kafka (`KAFKA_PRODUCER_MESSAGE_TIMEOUT_MS`).

## Decisões do runtime

- **Flags**: `Flagd::connect` com cache em APCu no FPM e em memória no CLI. Ambiente desconhecido conta como produção, e o `ProductionGuard` mantém as flags de caos desligadas.
- **Dependências passam pelo Toxiproxy** (`DB_HOST=toxiproxy`, `DB_PORT=13306`, `KAFKA_BROKERS=toxiproxy:19092`), para os experimentos de caos funcionarem sem mudar nada no serviço.
- **Kafka só na escrita**: o librdkafka sobe threads e conecta nos brokers assim que um producer existe, e quase todo request é leitura. Por isso o `LazyProducer` só cria o producer na primeira mensagem. O readiness não olha o Kafka, de propósito: sem ele o catálogo continua lendo e escrevendo, e o `catalog:republish` põe o tópico em dia depois.
- **Log do librdkafka desligado** (`log_level=0`): ele escreve linhas em texto puro no stderr, fora do formato JSON. Uma entrega que falha chega ao log como o warning JSON do serviço, com o motivo que o librdkafka devolveu.
- **Nada vai para o disco**: o log sai no stderr, inclusive o logger de emergência do Laravel, e o cache fica no Redis. Por isso o serviço não tem a pasta `storage/`.
