# Documentação

Mapa da documentação do projeto. A ordem abaixo também serve de **trilha de estudo**, de cima para baixo.

## 1. Engenharia

- [Convenções de engenharia](engineering/conventions.md): como o código é organizado e por quê.
- [Como contribuir](../CONTRIBUTING.md): Git Flow, commits, PRs, releases e hotfixes.

## 2. Arquitetura

- [Visão geral e C4](architecture/README.md): contexto, containers e fluxo principal.
- [Context map](architecture/context-map.md): contextos delimitados e padrões de integração.
- [Linguagem ubíqua](architecture/ubiquitous-language.md): o vocabulário do negócio.
- [Eventos](architecture/events.md): tópicos, CloudEvents, outbox, inbox e DLQ.
- [Identificadores](architecture/identifiers.md): UUIDv7 e Snowflake.
- [Modelo de dados](architecture/data-model.md): DDL, tipos, constraints e diagramas ER de cada banco.
- [Máquinas de estados](architecture/state-machines.md): pedido, pagamento e remessa.
- [Topologia local](architecture/deployment.md): redes, proxies de caos, listeners do Kafka e memória.
- [Feature flags](architecture/feature-flags.md): flags privadas por ambiente com OpenFeature e flagd.

## 3. Operação

- [Ambiente local](operations/local-environment.md): subir a stack, endereços, comandos e problemas comuns.

## 4. Laboratórios

- [Overselling](labs/overselling.md): cinco estratégias de reserva de estoque sob disputa real, e o que cada uma vende.
- [Circuit breaker](labs/circuit-breaker.md): o PSP fica lento, o circuito abre, e o checkout responde em milissegundos em vez de travar.
- [Conciliação de pagamentos](labs/payment-reconciliation.md): webhooks descartados e cobranças perdidas, e como a conciliação chega à palavra final do PSP.
- [Banco fora do ar](labs/database-outage.md): o relay que não voltava, o consumidor que desistia cedo, e o que mudou nos dois.
- [Fila de etiquetas](labs/label-queue.md): o bucket falha, o job tenta de novo, o `failed_jobs` guarda o resto e o replay do Kafka recupera o que sumiu.

## 5. Casos de uso

- [Lista ator-objetivo e fichas](use-cases/README.md), no formato de Alistair Cockburn.

## 6. Decisões

- [ADRs](adr/README.md): o porquê de cada escolha, com alternativas e consequências.
