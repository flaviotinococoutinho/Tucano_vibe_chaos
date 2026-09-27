# Decisões de arquitetura (ADRs)

Cada decisão que alguém vai questionar daqui a seis meses vira um ADR curto, no formato de Michael Nygard: **contexto**, **decisão**, **consequências** e alternativas consideradas. ADR não se edita depois de aceito; se a decisão mudar, um ADR novo substitui o antigo e os dois apontam um para o outro.

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
| [0011](0011-state-machines-without-flags.md) | Máquinas de estado com enum e guards em cadeia | aceito |
| [0012](0012-acid-writes-base-reads.md) | Escrita ACID em SQL, leitura BASE em NoSQL | aceito |
| [0013](0013-swoole-for-fleet-tracking.md) | Swoole para o tempo real da frota | aceito |
| [0014](0014-node-for-bff-and-partners.md) | Node.js no BFF e no simulador de parceiros | aceito |
| [0015](0015-feature-flags-openfeature-flagd.md) | Feature flags com OpenFeature e flagd | aceito |

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
