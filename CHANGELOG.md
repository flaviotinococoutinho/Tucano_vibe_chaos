# Changelog

Todas as mudanças relevantes ficam registradas aqui. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o versionamento segue o [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Added

- Etiqueta da remessa (UC-SHP-03): a ponte `logistics-label-requests` transforma cada `ShipmentCreated` em job na fila `label-jobs` do SQS, e o worker gera a etiqueta em ZPL (com escape contra injeção de comandos da impressora), grava no bucket `tucano-labels` e move a remessa para `ready_for_pickup`, com `ShipmentReadyForPickup` na outbox e contrato em JSON Schema. O SDK da AWS entra podado para S3 e SQS.
- `make flag` e `make flag-reset` trocam a variante de uma flag na cópia que o flagd observa, sem reiniciar nada.
- Laboratório da fila de etiquetas, com a flag `chaos.logistics.label-failure-rate`, os retries do job, o `failed_jobs` e o replay do Kafka.
- CarrierFake no `partners-sim`: as transportadoras do laboratório (frota própria e parceiros), com agendamento de coleta idempotente, a jornada da encomenda num relógio comprimido (coleta, hubs, saída para entrega, até três visitas, devolução), webhooks assinados em ordem, caos em tempo real e contrato OpenAPI. O envio de webhooks, a assinatura e as chaves de idempotência viraram módulos comuns ao PayFake e ao CarrierFake.
- A jornada da remessa até a porta (UC-SHP-04 a 08): a coleta agendada na transportadora quando a etiqueta fica pronta, e os webhooks assinados da transportadora aplicados na máquina de estados, com inbox, o comprovante e o motivo de cada visita em `delivery_attempts` e os sete eventos novos com contrato em JSON Schema.

### Changed

- O verificador da assinatura de webhook (`t=...,v1=...`) mora no pacote de mensageria, e o commerce e a logística usam o mesmo.

### Fixed

- Os jobs que esgotam as tentativas agora ficam no `failed_jobs` do PostgreSQL. O `config/queue.php` apontava para um SQLite que não existe, e o job que falhava sumia sem rastro.

## [0.4.0] - 2026-09-27

### Added

- Pedidos no commerce: `POST /v1/orders` (UC-ORD-01) com idempotência na mesma transação do pedido, reserva de estoque por CD com savepoint (UC-INV-01), `OrderPlaced` na outbox com contrato em JSON Schema, e `GET /v1/orders/{id}` (UC-ORD-05).
- Workers do commerce: relay da outbox (com pausa por flag de caos) e o consumidor `commerce.catalog-sync`, que mantém a cópia local do catálogo por versão (UC-ORD-06).
- Laboratório de overselling: estratégias de reserva `atomic`, `pessimistic`, `optimistic`, `serializable` e `naive`, escolhidas pela flag `inventory.reservation-strategy` ou, no laboratório, pelo header `X-Inventory-Strategy`, com teste de corrida entre processos.
- Expiração de pedidos não pagos (UC-ORD-03): worker `commerce-order-expiry` com `FOR UPDATE SKIP LOCKED`, liberação do estoque (UC-INV-03), histórico de transições gravado a partir do agregado e `OrderCancelled` na outbox com contrato em JSON Schema.
- Produtos no catálogo (UC-CAT-01 a 04): API `/v1/products`, cache-aside com jitter, cache negativo e lock contra stampede, concorrência otimista com `If-Match`, snapshot no tópico compactado depois do commit (dual write consciente) e `catalog:republish`, que o job de migração roda a cada subida.
- PayFake no `partners-sim`: cobranças com `Idempotency-Key`, ciclo de vida como união discriminada, webhooks assinados (`PayFake-Signature`, HMAC-SHA256 com timestamp) com retry e backoff, estornos, API de caos em tempo real e contrato OpenAPI 3.1 com os webhooks.
- Pagamento de pedidos (UC-PAY-01): `POST /v1/orders/{id}/payments` com idempotência, um pagamento pendente por pedido garantido pelo banco, chamada ao PayFake fora de transação com a camada anticorrupção, e circuit breaker com estado no Redis (`503` com `Retry-After` quando aberto).
- Laboratório de circuit breaker, com o experimento de latência no PSP e os números.
- Webhook do PayFake (UC-PAY-02): assinatura `PayFake-Signature` verificada sobre o corpo cru, inbox para evento repetido e, numa transação só, pagamento capturado ou recusado, pedido pago ou cancelado (UC-ORD-07), reserva convertida em venda (UC-INV-04) e `OrderPaid` na outbox; dinheiro que chega depois da expiração fica marcado para estorno.
- Job `contracts` no CI: os JSON Schemas de eventos são validados contra o metaschema e os contratos HTTP passam pelo lint do Redocly.
- Conciliação de pagamentos (UC-PAY-03) e estorno (UC-PAY-04): worker `commerce-payment-reconciler`, que pergunta ao PayFake pelos pagamentos sem desfecho há 60 s e aplica a resposta pelo mesmo caminho do webhook; estado `abandoned` para a cobrança que nunca chegou ao PSP, com a palavra tardia do PSP ainda aceita; estorno com o id do pagamento como `Idempotency-Key`; e o laboratório com o experimento.
- `GET /payfake/v1/charges?reference=` no PayFake, para achar a cobrança cujo id se perdeu junto com a resposta.
- Remessas na logística: o consumidor `logistics.order-intake` cria a remessa do pedido pago (UC-SHP-01) e cancela a do pedido cancelado (UC-SHP-09), com inbox e outbox na mesma transação; escolha de transportadora por uma corrente de regras (UC-SHP-02); cópia de peso e dimensões do catálogo (UC-SHP-11); a máquina de estados completa com seis guards; e `ShipmentCreated` e `ShipmentCancelled` em `logistics.shipments.v1`, com contrato em JSON Schema.

### Changed

- A leitura de CloudEvents nos consumidores ficou num lugar só: `IncomingEvent` no pacote de mensageria e `EventFields` no shared kernel. Um `time` que não é data agora torna o evento ilegível, e ele vai direto para a DLQ em vez de gastar as tentativas.
- O Ordering não conhece mais os erros do Inventory: o adapter traduz a recusa em `StockNotReserved`, com a mesma mensagem e categoria, como o Shipping já faz com o CarrierSelection.

### Fixed

- Os logs da librdkafka saem pelo logger do serviço, em JSON, e não mais em texto puro no stderr.
- O relay da outbox abre uma conexão nova depois de um lote que falhou. Antes, uma queda do banco deixava o relay preso num PDO morto, e os eventos paravam de sair até alguém reiniciar o worker.
- Consumidores Kafka: conexão perdida com o banco espera o banco voltar em vez de mandar a mensagem para a DLQ, e um `SIGTERM` no meio das tentativas não confirma o offset. Os dois experimentos estão no laboratório de banco fora do ar.

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

[Unreleased]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.4.0...develop
[0.4.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/flaviotinococoutinho/chaos_playground/releases/tag/v0.1.0
