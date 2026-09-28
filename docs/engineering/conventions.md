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
- O domínio e a aplicação não conhecem framework, banco, HTTP, feature flag nem SDK de fornecedor (AWS, MongoDB, Guzzle, Ramsey). Tudo isso mora nos adapters, e o Deptrac quebra o build se alguém tentar (camada `Vendor`, ADR 0022). Até o id dos eventos vem do shared kernel (`EventId`), e não da biblioteca que o gera.
- Um pacote chama outro pelo port de entrada dele, sempre por um adapter do lado de quem chama. O teste `PackagesMeetThroughTheirFacadesTest` cobra isso: o núcleo de um pacote não conhece outro pacote, e nenhum pacote toca os adapters de outro. Quando um pacote oferece algo às rotas de outro, ele publica um nome de capacidade (o middleware `reserves-stock` do Inventory), e não a classe. O adapter traduz nos dois sentidos: manda valores simples e devolve as recusas do outro lado como erro do próprio pacote, com a mesma mensagem e a mesma categoria (`StockNotReserved` no Ordering, `NoCarrierChosen` no Shipping). Assim, se o outro pacote virar um serviço, só o adapter muda.
- Nada de `Manager`, `Helper`, `Util` ou abreviações.

### Criar objetos: construtores nomeados, builders e domínio rico

- **Construtor privado e construtores nomeados** que dizem de onde o objeto vem:
  - `of(...)` monta a partir das partes: `Money::of(1500, Currency::brl())`, `Thoroughfare::of('Rua', 'da Bahia')`, `Quantity::of(2)`.
  - `from...(...)` converte de outra representação: `fromString`, `fromArray`, `fromJson`, `fromSnapshot`. Os enums já trazem `from` e `tryFrom`.
  - Um verbo do negócio quando criar é um fato do domínio: `Order::place(...)`, `Shipment::create(...)`, `Division::state(BrazilianState::MG)`, `ShippingLabel::storedAt(...)`.
- **`to...()` na saída**, simétrico ao `from...`: `toArray()`, `toJson()`, `toSnapshot()`, `toInt()`, `toBase32()`. O `jsonSerialize()` só repassa o `toArray()`.
- **Builder quando o objeto tem muitas partes ou partes opcionais.** O builder é mutável e fluente, recebe as partes na ordem em que as pessoas falam e valida o todo no `build()`, que devolve o objeto imutável (o Builder de Joshua Bloch). A mutabilidade fica no andaime, nunca no objeto pronto:

  ```php
  $address = Address::builder()
      ->thoroughfare('Rodovia', 'Fernão Dias')->number('KM 500')->complement('Galpão 3')
      ->state(BrazilianState::MG)->municipality('Betim', '3106705')
      ->postalCode('32669-000')
      ->build();
  ```

- **Domínio rico.** O comportamento mora junto dos dados, e quem usa pergunta ao objeto em vez de refazer a regra com os campos dele: `$address->state()`, `$address->thoroughfareLine()`, `$divisions->upTo(DivisionKind::Municipality)`, `$order->cancel(...)`. Nada de getter e setter para tudo.
- **Nos testes, test data builders** (`OrderBuilder`, `ShipmentBuilder`, `Addresses`) com padrões sensatos, para que cada teste escreva só o que importa para ele.

### Object Calisthenics no domínio

Aplico as nove regras de Jeff Bay com pragmatismo, principalmente nos pacotes de domínio: um nível de indentação por método, nada de `else`, primitivos embrulhados em value objects, coleções de primeira classe, um `->` por linha, nomes sem abreviação, classes pequenas, poucas variáveis de instância e tell, don't ask. Onde abro mão de alguma regra (DTOs, adapters, models do Eloquent), o motivo está em `docs/concepts/object-calisthenics.md`.

## Node.js e TypeScript

- Node 24 LTS executando `.ts` diretamente (type stripping nativo), só com sintaxe apagável: sem `enum`, `namespace` nem parameter properties. Para estados, use discriminated unions.
- ESM puro, `tsc --noEmit` para checar tipos e `node --test` para testes.
- Fastify nos servidores HTTP e logs JSON com pino.

## APIs HTTP

- Rotas versionadas (`/v1/...`) e JSON em `camelCase`.
- Erros no formato RFC 9457 (`application/problem+json`) em todos os serviços, PHP ou Node. O `type` é `about:blank` quando o status basta; quando dois problemas dividem um status e pedem respostas diferentes do cliente, o erro de domínio declara um nome com `#[ProblemType('...')]`, e o `type` vira a URI da seção que o explica em [`contracts/http/problems.md`](../../contracts/http/problems.md). Cliente decide pelo `type`, nunca lendo o `detail`.
- Só o erro inesperado esconde o `detail`. Um 503 deliberado, ou um erro de domínio de indisponibilidade, diz o que fazer e leva `Retry-After`.
- `X-Correlation-Id` é propagado em toda chamada (HTTP, Kafka, SQS) e aparece em todos os logs. O RFC 6648 desaconselha o prefixo `X-` em nomes novos, e mantive este de propósito: é o nome que gateways, APMs e o plugin do Kong já conhecem. O substituto padrão é o `traceparent` do W3C Trace Context, que entra junto com o OpenTelemetry. Header novo não leva `X-`.
- Comportamento opcional que o cliente pede usa o `Prefer` do RFC 7240, e a resposta confirma o que seguiu com `Preference-Applied` (a estratégia de reserva do laboratório de overselling é assim).
- `POST` que cria recurso exige `Idempotency-Key` (draft-ietf-httpapi-idempotency-key-header). A mesma chave com outro corpo é 422 (`idempotency-key-reused`), e uma resposta repetida leva `Idempotent-Replayed: true`, o nome que o mercado já usa, porque o draft não define um.
- Health checks em `/health/live` (o processo está de pé) e `/health/ready` (as dependências respondem).

## Mensageria

- Envelope CloudEvents 1.0 em JSON no modo estruturado do binding de Kafka: o evento inteiro é o valor da mensagem, com `content-type: application/cloudevents+json`, e o `subject` é a chave. Os headers `ce_type` e `correlation_id` são só atalhos para filtrar e rastrear sem abrir o valor; a verdade é o envelope. As extensões são `correlationid` e `causationid`.
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

Dado pessoal e de cartão entra no log só como máscara. Nome, e-mail, documento e token de cartão moram num `Sensitive` do shared kernel, que se imprime mascarado; o valor de verdade sai pelo `reveal()`, e só nos adapters e nos eventos de domínio. Mensagem de erro não repete o valor que recebeu. A fitness function `SensitiveDataLeavesOnPurposeTest` cobra a regra ([ADR 0024](../adr/0024-sensitive-data-behind-a-proxy.md)).

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
- **Testes de arquitetura**: casos de uso documentados, e pacotes que só se encontram pelas fachadas (os ports de entrada).
- **PHPStan e `tsc`**: tipos.
- **Pint e Biome**: estilo.
- **`pr-policy`**: nomes de branch, fluxo de destino e títulos de PR.
