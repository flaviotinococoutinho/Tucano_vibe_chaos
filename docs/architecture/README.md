# Arquitetura

A **Tucano** é um e-commerce fictício que entrega o que vende com uma malha logística própria (a Tucano Express) e com transportadoras parceiras. O sistema roda num notebook e cobre os problemas que aparecem em entrevistas de system design: concorrência no estoque, pagamento com saga, eventos entre contextos, leitura com consistência eventual, tempo real e falhas de rede.

## O que este projeto quer ensinar

- **PHP em três runtimes**: PHP-FPM (Laravel e Lumen, modelo shared-nothing) e Swoole (processo de longa duração com corrotinas).
- **Node.js** para I/O concorrente, WebSocket e agregação (BFF).
- **DDD** estratégico (context map, linguagem ubíqua) e tático (agregados, value objects, eventos).
- **Arquitetura hexagonal** e casos de uso no estilo de Alistair Cockburn, com package-by-feature.
- **ACID vs BASE** lado a lado: PostgreSQL e MySQL na escrita; MongoDB, DynamoDB e Redis na leitura e no lookup.
- **Padrões de sistemas distribuídos**: outbox, inbox, saga, circuit breaker, idempotência, rate limit, cache-aside, DLQ.
- **Engenharia do caos** com hipóteses, injeção de falhas controlada e aprendizado registrado.

## Contexto (C4 nível 1)

```mermaid
C4Context
  title Tucano - contexto do sistema (C4 nível 1)

  Person(customer, "Cliente", "Compra produtos e acompanha a entrega")
  Person(operator, "Operador logístico", "Acompanha remessas e a frota")
  Person(courier, "Entregador", "Coleta e entrega com o app da frota")

  System(tucano, "Tucano", "E-commerce com malha logística própria")

  System_Ext(psp, "PayFake", "Gateway de pagamento")
  System_Ext(carriers, "Transportadoras", "Parceiras de coleta e entrega")
  System_Ext(email, "E-mail", "Entrega das notificações")

  Rel(customer, tucano, "Compra e rastreia", "HTTPS, WebSocket")
  Rel(operator, tucano, "Opera remessas", "HTTPS")
  Rel(courier, tucano, "Posição e entregas", "WebSocket")
  BiRel(tucano, psp, "Cobranças e webhooks", "HTTPS")
  BiRel(tucano, carriers, "Envios e rastreio", "HTTPS")
  Rel(tucano, email, "Notifica", "SES")

  UpdateRelStyle(customer, tucano, $offsetX="-60", $offsetY="-10")
  UpdateRelStyle(courier, tucano, $offsetX="-20", $offsetY="-25")
  UpdateLayoutConfig($c4ShapeInRow="3", $c4BoundaryInRow="1")
```

O mundo externo (PSP, transportadoras e o app dos entregadores) é simulado pelo serviço `partners-sim`, que também expõe controles de caos.

## Containers (C4 nível 2)

```mermaid
C4Container
  title Tucano - containers (C4 nível 2)

  Person(customer, "Cliente / Operador")
  System_Ext(partners, "Parceiros", "PSP, transportadoras e app da frota (partners-sim)")

  System_Boundary(edge, "Borda") {
    Container(kong, "API Gateway", "Kong 3.9, DB-less", "Rotas, rate limit, correlation id")
    Container(web, "Web", "React 19 + Vite", "Loja, operações e laboratórios")
    Container(bff, "BFF", "Node 24 + Fastify", "Agregação e WebSocket ao vivo")
  }

  System_Boundary(services, "Serviços") {
    Container(catalog, "Catalog", "Lumen 11, PHP 8.3", "Produtos e preços")
    Container(commerce, "Commerce", "Laravel 13, PHP 8.4", "Pedidos, estoque, pagamentos")
    Container(logistics, "Logistics", "Laravel 13, PHP 8.4", "Remessas, transportadoras, etiquetas")
    Container(tracking, "Tracking", "Swoole 6, PHP 8.4", "Frota em tempo real e despacho")
  }

  System_Boundary(data, "Dados e mensageria") {
    ContainerDb(mysql, "MySQL 8.4", "ACID", "catalog")
    ContainerDb(postgres, "PostgreSQL 18", "ACID", "commerce, logistics")
    ContainerDb(mongo, "MongoDB 8", "BASE", "read models")
    ContainerDb(redis, "Redis 8", "memória", "cache, GEO, locks")
    ContainerQueue(kafka, "Kafka 3.9 + ZooKeeper", "log de eventos", "eventos de domínio")
    Container(aws, "AWS local", "Floci", "S3, SQS, SNS, DynamoDB, SES")
    Container(flags, "Feature flags", "flagd", "Flags privadas por ambiente")
  }

  Rel(customer, kong, "HTTPS, WSS")
  Rel(partners, kong, "webhooks, WSS")
  Rel(kong, web, "/")
  Rel(kong, bff, "/bff")
  Rel(kong, tracking, "/ws")
  Rel(bff, commerce, "HTTP")
  Rel(bff, logistics, "HTTP")
  Rel(bff, catalog, "HTTP")
  Rel(catalog, mysql, "SQL")
  Rel(commerce, postgres, "SQL")
  Rel(logistics, postgres, "SQL")
  Rel(tracking, redis, "GEO, pub/sub")
  Rel(commerce, kafka, "outbox")
  Rel(logistics, kafka, "outbox")
  Rel(logistics, aws, "S3, SQS, SNS")
  Rel(commerce, mongo, "projeções")

  UpdateLayoutConfig($c4ShapeInRow="4", $c4BoundaryInRow="1")
```

## Por que cada peça existe

| Container | Tecnologia | Motivo | Status |
|---|---|---|---|
| `kong` | Kong Gateway 3.9 OSS, DB-less | borda única: rotas, rate limit, correlation id, cache e circuit breaking por health check | rodando, rotas `/bff`, `/api/catalog`, `/api/commerce`, `/api/logistics` e `/api/tracking` |
| `web` | React 19 + Vite | loja, console de operações, laboratórios e painel de caos | planejado |
| `bff` | Node 24 + Fastify | agrega dados para a web e empurra eventos do Kafka por WebSocket | esqueleto rodando (health, erros) |
| `catalog` | Lumen 11, PHP 8.3 | subdomínio de suporte, leitura intensa e cache-aside, no papel de serviço legado | produtos com cache-aside e snapshots no tópico compactado |
| `commerce` | Laravel 13, PHP 8.4 | núcleo transacional: pedidos, estoque, pagamentos e notificações | pedidos (fazer e consultar) com reserva de estoque e outbox |
| `logistics` | Laravel 13, PHP 8.4 | núcleo logístico: remessas, máquina de estados, transportadoras, etiquetas | esqueleto rodando (health, erros, flags) |
| `tracking` | Swoole 6, PHP 8.4 | milhares de conexões de GPS e WebSocket em um processo de longa duração | esqueleto rodando (health, erros) |
| `partners-sim` | Node 24 + Fastify | simula o mundo externo: PSP, transportadoras e app da frota, com controles de caos | esqueleto rodando (health, erros) |
| `nginx` | nginx 1.30 | servidor web das aplicações PHP-FPM | rodando, esperando os apps |
| `postgres` | PostgreSQL 18 | escrita ACID de commerce e logistics, com `uuidv7()` nativo | rodando |
| `mysql` | MySQL 8.4 | escrita ACID do catálogo (InnoDB, `REPEATABLE READ`) | rodando |
| `mongo` | MongoDB 8 | read models com consistência eventual (CQRS) | rodando |
| `redis` | Redis 8 | cache, GEO da frota, locks, rate limit e estado de circuit breaker | rodando |
| `kafka` + `zookeeper` | Apache Kafka 3.9.2 | backbone de eventos entre contextos | rodando |
| `floci` | Floci 2.1 | S3, SQS, SNS, DynamoDB e SES locais | rodando |
| `toxiproxy` | Toxiproxy 2.12 | injeção de latência, cortes e timeouts entre serviços e dependências | rodando |
| `mailpit` | Mailpit | caixa de entrada dos e-mails enviados pelo SES local | rodando |
| `flagd` | flagd 0.17 (OpenFeature) | flags privadas por ambiente, avaliadas no servidor | rodando |

## Fluxo principal: comprar e receber

```mermaid
sequenceDiagram
  autonumber
  actor C as Cliente
  participant W as Web + BFF
  participant CM as Commerce
  participant PSP as PayFake
  participant K as Kafka
  participant LG as Logistics
  participant TR as Tracking
  actor E as Entregador

  C->>W: fecha o pedido
  W->>CM: POST /v1/orders (Idempotency-Key)
  CM->>CM: reserva estoque e grava OrderPlaced na outbox
  C->>W: paga
  W->>CM: POST /v1/orders/{id}/payments
  CM->>PSP: cobra (Idempotency-Key)
  PSP-->>CM: webhook charge.succeeded
  CM->>K: OrderPaid (via outbox)
  K->>LG: OrderPaid
  LG->>LG: cria a remessa, escolhe a transportadora e gera a etiqueta
  LG->>TR: pede o entregador mais próximo
  TR-->>E: atribuição (WebSocket)
  E->>TR: posição GPS
  TR-->>C: posição ao vivo
  E->>LG: entrega confirmada
  LG->>K: ShipmentDelivered (via outbox)
  K->>CM: pedido entregue
  K->>W: atualização em tempo real
```

## Para continuar

- [Context map](context-map.md): os contextos delimitados e como conversam.
- [Linguagem ubíqua](ubiquitous-language.md): o vocabulário do negócio e seus nomes no código.
- [Casos de uso](../use-cases/README.md): lista ator-objetivo e fichas no formato de Cockburn.
- [Eventos](events.md): tópicos, envelope CloudEvents e garantias de entrega.
- [Identificadores](identifiers.md): UUIDv7 e Snowflake.
- [Modelo de dados](data-model.md): DDL, tipos e constraints de cada banco.
- [Máquinas de estados](state-machines.md): pedido, pagamento e remessa, sem flags booleanas.
- [Topologia local](deployment.md): redes, proxies de caos e memória.
- [Feature flags](feature-flags.md): flags privadas por ambiente.
- [Decisões de arquitetura (ADRs)](../adr/README.md).
