# Decisões de arquitetura (ADRs)

Toda decisão que alguém vai questionar daqui a seis meses vira um ADR curto, no formato de Michael Nygard: contexto, decisão, consequências e alternativas consideradas. ADR aceito não é editado; se a decisão mudar, um ADR novo substitui o antigo e os dois apontam um para o outro.

| ADR | Decisão | Status |
|---|---|---|
| [0001](0001-record-architecture-decisions.md) | Registrar decisões de arquitetura | aceito |
| [0002](0002-services-per-subdomain.md) | Serviços por subdomínio, sem microsserviços por entidade | aceito |
| [0003](0003-package-by-feature-hexagonal.md) | Package-by-feature com hexágono e casos de uso de Cockburn | aceito |
| [0004](0004-kafka-with-zookeeper.md) | Kafka 3.9 em modo ZooKeeper | aceito |
| [0005](0005-floci-local-aws.md) | Floci como AWS local | aceito |
| [0006](0006-kong-db-less.md) | Kong 3.9 OSS em modo DB-less | aceito |
| [0007](0007-uuidv7-and-snowflake.md) | UUIDv7 para identidade e Snowflake para números rastreáveis | aceito |
| [0008](0008-transactional-outbox.md) | Transactional Outbox, com uma exceção consciente no catálogo | aceito |
| [0009](0009-lumen-legacy-service.md) | Lumen como serviço legado | aceito |
| [0010](0010-cloudevents-contracts.md) | Envelope CloudEvents e contratos versionados | aceito |
| [0011](0011-state-machines-without-flags.md) | Máquinas de estados com enum e guards em cadeia | aceito |
| [0012](0012-acid-writes-base-reads.md) | Escrita ACID em SQL, leitura BASE em NoSQL | aceito |
| [0013](0013-swoole-for-fleet-tracking.md) | Swoole para o tempo real da frota | aceito |
| [0014](0014-node-for-bff-and-partners.md) | Node.js no BFF e no simulador de parceiros | aceito |
| [0015](0015-feature-flags-openfeature-flagd.md) | Feature flags com OpenFeature e flagd | aceito |
| [0016](0016-copied-node-platform.md) | Plataforma Node copiada, não compartilhada | aceito |
| [0017](0017-wait-for-the-database-not-the-dlq.md) | Esperar o banco voltar em vez de mandar para a DLQ | aceito |
| [0018](0018-async-work-starts-from-the-event.md) | Começar o trabalho assíncrono pelo evento, não por um dispatch depois do commit | aceito |
| [0019](0019-abandoned-payments-accept-late-outcomes.md) | Desistir de um pagamento sem fechar a porta para o PSP | aceito |
| [0020](0020-address-by-thoroughfare-and-divisions.md) | Modelar o endereço por logradouro e divisões territoriais | aceito |
| [0021](0021-configuration-from-the-environment.md) | Configurar tudo pelo ambiente, com a unidade no nome | aceito |
| [0022](0022-vendors-live-in-adapters.md) | Fornecedor mora só nos adapters | aceito |
| [0023](0023-server-driven-ui-with-siren.md) | Telas dirigidas pelo servidor, com hipermídia (Siren) | aceito |
| [0024](0024-sensitive-data-behind-a-proxy.md) | Dado sensível passa por um proxy | aceito |
| [0025](0025-chaos-experiments-as-code.md) | Experimentos de caos como código, com o Chaos Toolkit | aceito |
| [0026](0026-a-database-outage-is-unavailability.md) | Tratar banco fora do ar como indisponibilidade, não como erro interno | aceito |
| [0027](0027-one-consumer-group-per-read-model.md) | Um grupo de consumo por read model | aceito |

## Modelo

```markdown
# NNNN. Título no imperativo

- Status: proposto | aceito | substituído por NNNN
- Data: AAAA-MM-DD

## Contexto
## Decisão
## Consequências
## Alternativas consideradas
```
