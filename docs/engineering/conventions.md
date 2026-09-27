# Convenções de engenharia

Escrevi estas convenções para manter a **integridade conceitual** do projeto (Fred Brooks): são cinco runtimes, mas um só jeito de organizar, nomear e integrar. Quando uma convenção atrapalha, a mudança é feita aqui, com um ADR, e não como exceção silenciosa no código.

## Idioma

| O quê | Idioma |
|---|---|
| código, nomes, comentários, logs, mensagens de erro, commits, PRs, CLI | inglês |
| README, `docs/`, ADRs, casos de uso, runbooks de caos | português (pt-BR), mantendo termos técnicos em inglês |

## Regras gerais

- **Uma forma de fazer cada coisa.** Antes de trazer uma lib ou um padrão novo, verifique se o que já existe resolve. Duas soluções para o mesmo problema duplicam manutenção e revisão.
- **Decisão relevante vira ADR** em `docs/adr/` (contexto, decisão, consequências).
- **Diagramas acompanham o código.** C4 e diagramas de sequência em Mermaid são atualizados no mesmo PR que muda a arquitetura.
- **Código morto é removido**, não comentado.
- **Configuração por variáveis de ambiente** (12-factor). Os valores do `compose.yaml` servem só para desenvolvimento local.

## PHP

- `declare(strict_types=1);` em todo arquivo, classes `final` por padrão e value objects `readonly`.
- Tipos em tudo (parâmetros, retornos e propriedades). Nada de `mixed`, a não ser na borda de serialização.
- Estilo verificado pelo Pint, análise estática com PHPStan e regras de camadas com Deptrac.
- Estados são backed enums, nunca combinações de booleanos (`isPaid`, `isShipped`...). As máquinas de estados estão em `docs/architecture/state-machines.md`.
- Dinheiro é inteiro em centavos com a moeda; tempo é sempre UTC com `DateTimeImmutable`, lido de um relógio injetável.
- Exceções de domínio são específicas e nomeadas pelo problema (`InsufficientStock`, `TransitionNotAllowed`), sem sufixo `Exception`.

### Organização: package-by-feature + hexágono

O código de negócio fica em `src/`, agrupado por subdomínio (package-by-feature). Dentro de cada pacote vale o hexágono de Alistair Cockburn:

```text
src/Shipping/
├── Domain/                    agregados, value objects, eventos, regras
├── Application/
│   ├── Port/Driving/          o que o mundo pode pedir: ForCreatingShipments
│   ├── Port/Driven/           o que a aplicação precisa: ForStoringShipments
│   └── UseCase/               implementações dos ports de entrada: CreateShipment
└── Adapter/
    ├── Driving/               HTTP, console, consumidores de mensagens
    └── Driven/                banco, Kafka, S3, APIs de parceiros
```

- Ports seguem a convenção de Cockburn: `For` + verbo no gerúndio + substantivo (`ForPlacingOrders`, `ForChargingPayments`).
- Casos de uso têm nome de ação (`PlaceOrder`, `ConfirmDelivery`); eventos ficam no passado (`OrderPlaced`, `ShipmentDelivered`).
- Todo caso de uso carrega `#[UseCase('UC-XXX-00')]` e tem ficha em `docs/use-cases/`. Um teste de arquitetura garante isso.
- O domínio não conhece framework, banco nem HTTP; o Deptrac quebra o build se alguém tentar.
- Nada de `Manager`, `Helper`, `Util` ou abreviações.

### Object Calisthenics no domínio

Aplico as nove regras de Jeff Bay com pragmatismo, principalmente nos pacotes de domínio: um nível de indentação por método, nada de `else`, primitivos embrulhados em value objects, coleções de primeira classe, um `->` por linha, nomes sem abreviação, classes pequenas, poucas variáveis de instância e tell, don't ask. Onde abro mão de alguma regra (DTOs, adapters, models do Eloquent), o motivo está em `docs/concepts/object-calisthenics.md`.

## Node.js e TypeScript

- Node 24 LTS executando `.ts` diretamente (type stripping nativo), só com sintaxe apagável: sem `enum`, `namespace` nem parameter properties. Para estados, use discriminated unions.
- ESM puro, `tsc --noEmit` para checar tipos e `node --test` para testes.
- Fastify nos servidores HTTP e logs JSON com pino.

## APIs HTTP

- Rotas versionadas (`/v1/...`) e JSON em `camelCase`.
- Erros no formato RFC 9457 (`application/problem+json`) em todos os serviços, PHP ou Node.
- `X-Correlation-Id` é propagado em toda chamada (HTTP, Kafka, SQS) e aparece em todos os logs.
- `POST` que cria recurso exige `Idempotency-Key`.
- Health checks em `/health/live` (o processo está de pé) e `/health/ready` (as dependências respondem).

## Mensageria

- Envelope CloudEvents 1.0 em JSON (modo estruturado), com as extensões `correlationid` e `causationid`.
- Tópicos no formato `<contexto>.<agregado-no-plural>.v<n>` (`logistics.shipments.v1`), com a chave igual ao id do agregado para garantir ordem por agregado.
- Entrega at-least-once: todo consumidor é idempotente (tabela de inbox) e poison messages vão para a DLQ.
- Quem publica evento de domínio usa Transactional Outbox. A única exceção é proposital e está documentada em ADR.

## Bancos de dados

- Um schema por serviço. Nenhum serviço lê a base de outro.
- Migrations com DDL explícito: constraints (`CHECK`, `FOREIGN KEY`, `UNIQUE`) ficam no banco, não só no código.
- Identidade com UUIDv7, em `uuid` no PostgreSQL e `BINARY(16)` no MySQL, nunca `CHAR(36)`. Números públicos e rastreáveis (pedido, código de rastreio) usam Snowflake de 64 bits em `BIGINT`. Veja `docs/architecture/identifiers.md`.
- `CHAR(n)` só para valor de tamanho fixo: UF em `CHAR(2)`, país ISO 3166-1 em `CHAR(2)`, moeda ISO 4217 em `CHAR(3)`, CEP em `CHAR(8)` só com dígitos, código do centro de distribuição em `CHAR(4)` (`GRU1`) e código de rastreio formatado em `CHAR(15)`. Texto variável usa `VARCHAR(n)` com limite real (SKU `VARCHAR(32)`, e-mail `VARCHAR(254)`); texto livre usa `TEXT`.
- Dinheiro em `BIGINT` (centavos) com a moeda em `CHAR(3)`.
- Tabelas no plural e em `snake_case`; datas em `timestamptz` (PostgreSQL) ou `DATETIME(6)` em UTC (MySQL).

## Logs

Uma linha JSON por evento, sempre com `timestamp`, `level`, `service`, `message` e `correlation_id`.

## Testes

| Nível | O que cobre | Onde roda |
|---|---|---|
| unidade | domínio puro, sem framework | CI e local |
| aplicação | casos de uso com adapters em memória | CI e local |
| integração | adapters contra bancos reais | CI (containers de serviço) e compose |
| contrato | eventos validados contra `contracts/` | CI |
| ponta a ponta | fluxo completo pela stack | `make smoke` e antes de cada release |

O nome do teste descreve comportamento: `it_refuses_a_fourth_delivery_attempt`, e não `testAttempts`.

## Controle de entropia

Estas são as fitness functions do projeto. Se alguma quebra, o PR não entra:

- **Deptrac**: direção das dependências entre camadas e pacotes.
- **Testes de arquitetura**: casos de uso documentados e domínio livre de framework.
- **PHPStan e `tsc`**: tipos.
- **Pint e Biome**: estilo.
- **`pr-policy`**: nomes de branch, fluxo de destino e títulos de PR.
