# Changelog

Todas as mudanças relevantes ficam registradas aqui. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o versionamento segue o [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Added

- Pedidos no commerce: `POST /v1/orders` (UC-ORD-01) com idempotência na mesma transação do pedido, reserva de estoque por CD com savepoint (UC-INV-01), `OrderPlaced` na outbox com contrato em JSON Schema, e `GET /v1/orders/{id}` (UC-ORD-05).
- Workers do commerce: relay da outbox (com pausa por flag de caos) e o consumidor `commerce.catalog-sync`, que mantém a cópia local do catálogo por versão (UC-ORD-06).
- Laboratório de overselling: estratégias de reserva `atomic`, `pessimistic`, `optimistic`, `serializable` e `naive`, escolhidas pela flag `inventory.reservation-strategy` ou, no laboratório, pelo header `X-Inventory-Strategy`, com teste de corrida entre processos.

## [0.3.0] - 2026-09-27

### Added

- Imagem base do PHP (8.3 e 8.4) com as extensões dos serviços e nginx na frente dos apps PHP-FPM.
- Pacote `tucano/feature-flags`: porta `FeatureFlags` com OpenFeature e flagd, cache de avaliação (APCu ou memória) e guard que desliga flags de caos e laboratório em produção.
- `DomainError` com `ErrorCategory` no shared kernel, para o domínio dizer o tipo do problema sem conhecer HTTP.
- Pacote `tucano/messaging`: producer idempotente, consumer at-least-once com retry e DLQ, relay da outbox com `SKIP LOCKED` e inbox, testado contra PostgreSQL e Kafka reais.
- Read models no MongoDB: pacote `tucano/read-models` (migrations com `$jsonSchema` e upsert por versão), coleções `order_views` e `shipment_timelines` criadas no job de migração.
- Serviço `commerce` (Laravel 13, PHP 8.4): esqueleto de API com health checks, erros RFC 9457, correlation id, flags e Snowflake, exposto pelo Kong em `/api/commerce`.
- Serviço `logistics` (Laravel 13, PHP 8.4) com a mesma estrutura do commerce, exposto pelo Kong em `/api/logistics`.
- Schemas PostgreSQL do commerce e do logistics com DDL explícito (constraints, índices parciais, comentários), seeders idempotentes, jobs de migração no compose e testes das constraints contra o banco real.
- Serviço `catalog` (Lumen 11, PHP 8.3) no papel de serviço legado: health checks, erros RFC 9457, correlation id e flags, exposto pelo Kong em `/api/catalog`.
- Schema MySQL do catálogo com DDL explícito (UUIDv7 em `BINARY(16)`, `ENUM`, `CHECK` com `REGEXP_LIKE`), seeders idempotentes com os produtos do estoque, job `catalog-migrate` e testes das constraints.
- Serviço `tracking` (Swoole 6.2, PHP 8.4): servidor HTTP e WebSocket de longa duração com health checks, erros RFC 9457, correlation id no contexto da corrotina e graceful shutdown, exposto pelo Kong em `/api/tracking`.
- Serviços `bff` e `partners-sim` (Node 24, Fastify 5, TypeScript sem build): health checks, erros RFC 9457, correlation id e logs JSON. O bff fica no Kong em `/bff`; o simulador fica só na rede `edge`, e os serviços chegam nele pelo Toxiproxy.
- `make check s=<serviço>` para qualquer serviço: PHP contra a stack, Node no `node:24-alpine`.
- Modelo de dados com o uso do Redis e as tabelas do DynamoDB.

### Changed

- A tag de release sai direto da `origin/main`, sem trocar o working tree: a troca apagava e recriava arquivos montados pelos containers da stack.

### Fixed

- Dependência muda não prende mais o PHP-FPM: timeouts de conexão e leitura em PostgreSQL, MySQL, Redis e MongoDB, `request_terminate_timeout` no FPM e health check sem o retry da query. Com um banco em blackhole, o readiness responde 503 em 2 a 4 s, e não mais 504 depois de 30 s.
- O 405 dos serviços PHP volta com o header `Allow`: o problem details descartava os headers da exceção HTTP.

## [0.2.0] - 2026-09-27

### Added

- Stack local em Docker Compose: PostgreSQL 18, MySQL 8.4, MongoDB 8, Redis 8, Kafka 3.9 com ZooKeeper, Floci, Mailpit, Toxiproxy, flagd e Kong 3.9.
- Feature flags privadas por ambiente (local, staging e production) com OpenFeature e flagd.
- Comandos `make` para operar a stack e job de CI que valida compose, configuração do Kong, flags e scripts.
- Shared kernel PHP (`tucano/shared-kernel`): UUIDv7, Snowflake com sequência em APCu, Base32 de Crockford, `Money`, `Clock`, envelope CloudEvents e o atributo `#[UseCase]`, testado em PHP 8.3 e 8.4.

### Changed

- Documentação revisada: tom direto, primeira pessoa, pontuação simples, tipos de dados precisos e direções corrigidas no context map.

## [0.1.0] - 2026-09-27

### Added

- Estrutura inicial do repositório, Git Flow e convenções de engenharia.
- Blueprint de arquitetura: C4, context map, linguagem ubíqua, eventos, identificadores, máquinas de estados, casos de uso e ADRs 0001 a 0014.
- Fluxo de release: tags SemVer imutáveis e GitHub Release gerada a partir deste changelog.

[Unreleased]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.3.0...develop
[0.3.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/flaviotinococoutinho/chaos_playground/releases/tag/v0.1.0
