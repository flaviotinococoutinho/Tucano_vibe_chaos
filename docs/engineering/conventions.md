# Convenções de engenharia

Este documento existe para proteger a **integridade conceitual** do projeto (Fred Brooks): o sistema tem que parecer escrito por uma cabeça só, mesmo com cinco runtimes diferentes. Quando uma convenção atrapalhar, mude a convenção aqui, com um ADR, em vez de abrir uma exceção silenciosa no código.

## Idioma

| O quê | Idioma |
|---|---|
| código, nomes, comentários, logs, mensagens de erro, commits, PRs, CLI | inglês |
| README, `docs/`, ADRs, casos de uso, runbooks de caos | português (pt-BR), mantendo termos técnicos em inglês |

## Regras gerais

- **Uma forma de fazer cada coisa.** Antes de trazer uma lib ou um padrão novo, verifique se o que já existe resolve. Duas soluções para o mesmo problema é entropia.
- **Decisão relevante vira ADR** em `docs/adr/` (contexto, decisão, consequências). Decisão sem registro vira folclore.
- **Diagramas acompanham o código.** C4 e sequências em Mermaid são atualizados no mesmo PR que muda a arquitetura.
- **Código morto é removido**, não comentado.
- **Configuração por variáveis de ambiente** (12-factor). Os valores do `compose.yaml` são só para desenvolvimento local.

## PHP

- `declare(strict_types=1);` em todo arquivo, classes `final` por padrão e *value objects* `readonly`.
- Tipos em tudo (parâmetros, retornos e propriedades). Nada de `mixed`, a não ser na borda de serialização.
- Estilo verificado pelo **Pint**, análise estática com **PHPStan** e regras de camadas com **Deptrac**.
- Estados são `enum` (backed), nunca combinações de booleanos (`isPaid`, `isShipped`...). Veja as máquinas de estado em `docs/architecture/state-machines.md`.
- Dinheiro é inteiro em centavos com moeda; tempo é sempre UTC com `DateTimeImmutable`, lido através de um relógio injetável.
- Exceções de domínio são específicas e nomeadas pelo problema (`InsufficientStock`, `TransitionNotAllowed`), sem sufixo `Exception`.

### Organização: package-by-feature + hexágono

O código de negócio fica em `src/`, agrupado por **subdomínio** (pacote por feature). Dentro de cada pacote, o hexágono de Alistair Cockburn:

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
- Todo caso de uso carrega `#[UseCase('UC-XXX-00')]` e tem sua ficha em `docs/use-cases/`. Um teste de arquitetura garante isso.
- O domínio não conhece framework, banco ou HTTP; o Deptrac quebra o build se alguém tentar.
- Nada de `Manager`, `Helper`, `Util` ou abreviações.

### Object Calisthenics no domínio

Aplicamos as nove regras de Jeff Bay com pragmatismo, principalmente nos pacotes de domínio: um nível de indentação por método, nada de `else`, primitivos embrulhados em value objects, coleções de primeira classe, um `->` por linha, nomes sem abreviação, classes pequenas, poucas variáveis de instância e *tell, don't ask*. Onde abrimos mão de alguma regra (DTOs, adapters, models do Eloquent), o motivo está em `docs/concepts/object-calisthenics.md`.

## Node.js e TypeScript

- Node 24 LTS executando `.ts` diretamente (*type stripping* nativo), só com sintaxe apagável: sem `enum`, `namespace` ou *parameter properties*. Para estados, use *discriminated unions*.
- ESM puro, `tsc --noEmit` para checar tipos e `node --test` para testes.
- Fastify nos servidores HTTP e logs JSON com pino.

## APIs HTTP

- Rotas versionadas (`/v1/...`) e JSON em `camelCase`.
- Erros no formato **RFC 9457** (`application/problem+json`) em todos os serviços, PHP ou Node.
- `X-Correlation-Id` é propagado em toda chamada (HTTP, Kafka, SQS) e aparece em todos os logs.
- `POST` que cria recurso exige `Idempotency-Key`.
- Health checks em `/health/live` (o processo está de pé) e `/health/ready` (as dependências respondem).

## Mensageria

- Envelope **CloudEvents 1.0** em JSON (modo estruturado), com as extensões `correlationid` e `causationid`.
- Tópicos no formato `<contexto>.<agregado-no-plural>.v<n>` (`logistics.shipments.v1`), com a chave igual ao id do agregado para garantir ordem por agregado.
- Entrega *at-least-once*: todo consumidor é idempotente (tabela de *inbox*) e mensagens venenosas vão para DLQ.
- Quem publica evento de domínio usa **Transactional Outbox**. A exceção consciente está documentada em ADR.

## Bancos de dados

- Um schema por serviço. Nenhum serviço lê a base de outro.
- Migrations com DDL explícito: constraints (`CHECK`, `FOREIGN KEY`, `UNIQUE`) moram no banco, não só no código.
- Identidade com **UUIDv7**. Números públicos e rastreáveis (pedido, código de rastreio) usam **Snowflake** de 64 bits. Veja `docs/architecture/identifiers.md`.
- Tabelas no plural e em `snake_case`; datas em `timestamptz` (PostgreSQL) ou `DATETIME(6)` UTC (MySQL).

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

Estas são as *fitness functions* do projeto. Se alguma quebra, o PR não entra:

- **Deptrac**: direção das dependências entre camadas e pacotes.
- **Testes de arquitetura**: casos de uso documentados e domínio livre de framework.
- **PHPStan e `tsc`**: tipos.
- **Pint e Biome**: estilo.
- **`pr-policy`**: nomes de branch, fluxo de destino e títulos de PR.
