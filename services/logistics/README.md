# logistics

Serviço de remessas da Tucano, em Laravel 13 sobre PHP 8.4 (FPM). Aqui fica o núcleo logístico: criar a remessa quando o pedido é pago, escolher a transportadora, gerar a etiqueta e conduzir a entrega pela máquina de estados até o cliente (ou de volta ao CD).

A estrutura é a mesma do [commerce](../commerce/README.md), de propósito: dois núcleos, um jeito só de organizar. Nesta fatia a remessa nasce do `order.paid` e pode ser cancelada pelo `order.cancelled`; a etiqueta e a entrega entram nas próximas.

## Como está organizado

| Pasta | O que tem |
|---|---|
| `src/Shipping` | a remessa e a máquina de estados inteira, a cópia local do catálogo (peso e dimensões) e o consumidor dos eventos de pedido |
| `src/CarrierSelection` | a corrente de regras que escolhe a transportadora |
| `src/Shared` | os ports que todos os pacotes usam: transação, inbox, outbox e o relay |
| `app/` | cola com o Laravel: health checks, correlation id, problem details e o provider de plataforma |
| `config/platform.php` | flags, Snowflake (worker 11) e nome do serviço |
| `config/messaging.php` | brokers do Kafka, pelo Toxiproxy |
| `tests/Contract` | os eventos publicados e as fixtures consumidas, validados contra `contracts/events` |
| `tests/Architecture` | fitness functions (casos de uso documentados) |
| `deptrac.yaml` | regra de dependência entre as camadas |

Cada pacote tem o hexágono completo, descrito em [`src/README.md`](src/README.md). O Shipping chega na escolha de transportadora pelo port de saída `ForChoosingCarriers`, e o adapter `CarrierSelectionChoices` chama o port de entrada do CarrierSelection com valores simples. Na volta, o adapter também traduz as recusas do CarrierSelection em `NoCarrierChosen`, com a mesma categoria, então o Shipping não conhece nenhum tipo do outro pacote. Se a escolha virar um serviço, só o adapter muda.

## A máquina de estados da remessa

É a máquina principal do projeto e segue a tabela de [`docs/architecture/state-machines.md`](../../docs/architecture/state-machines.md). O estado é um backed enum (`ShipmentStatus`) com a tabela num `match` exaustivo; nenhuma flag booleana. Entre parênteses está o guard que cada transição atravessa.

```mermaid
stateDiagram-v2
  [*] --> created: OrderPaid
  created --> ready_for_pickup: etiqueta gerada (LabelMustBeAttached)
  created --> cancelled: pedido cancelado
  ready_for_pickup --> picked_up: coleta
  ready_for_pickup --> cancelled: pedido cancelado
  picked_up --> in_transit: passagem por hub (HubRequired)
  picked_up --> out_for_delivery: frota própria (AttemptsBelowLimit)
  in_transit --> in_transit: novo hub (HubRequired)
  in_transit --> out_for_delivery: última milha (AttemptsBelowLimit)
  out_for_delivery --> delivered: comprovante (ProofOfDeliveryRequired)
  out_for_delivery --> delivery_failed: ausente ou endereço (FailureReasonRequired)
  delivery_failed --> out_for_delivery: nova tentativa (AttemptsBelowLimit)
  delivery_failed --> returning: 3 tentativas ou recusa (ReturnAllowed)
  returning --> returned: chegou ao CD
  delivered --> [*]
  returned --> [*]
  cancelled --> [*]
```

A tabela diz se a transição existe; os guards dizem se ela pode acontecer com os dados que chegaram. Os guards formam uma corrente (Chain of Responsibility): cada um é uma classe pequena, com um `if` só, que confere a própria regra e passa o pedido adiante. O primeiro que recusar lança `TransitionRefused` com o motivo.

```mermaid
flowchart LR
  request["transição pedida"] --> table{"existe na tabela?"}
  table -- não --> denied["TransitionNotAllowed"]
  table -- sim --> label["LabelMustBeAttached"] --> hub["HubRequired"] --> proof["ProofOfDeliveryRequired"] --> reason["FailureReasonRequired"] --> attempts["AttemptsBelowLimit"] --> back["ReturnAllowed"] --> applied["transição aplicada<br/>histórico + evento"]
  label & hub & proof & reason & attempts & back -. recusa .-> refused["TransitionRefused"]
```

- Falta de evidência (etiqueta, hub, comprovante ou motivo da falha) é `InvalidInput`; estourar as tentativas ou voltar sem justificativa é `Conflict`. Transição fora da tabela é `TransitionNotAllowed`, também `Conflict`.
- Cada transição aplicada muda o estado, sobe a versão, acrescenta uma linha ao histórico (`shipment_transitions`, com o motivo e o hub) e registra um evento de domínio. O tipo do evento é o nome do estado alcançado (`tucano.logistics.shipment.picked_up`), exatamente a lista de `docs/architecture/events.md`.
- A máquina inteira já mora no domínio e cada transição e cada guard têm teste de unidade, mas nesta fatia só `created` e `cancelled` são movidos por casos de uso.

## Criar e cancelar a remessa

O worker `logistics:order-intake` lê `commerce.orders.v1` no consumer group `logistics.order-intake`.

| Evento | O que acontece |
|---|---|
| `order.paid` | cria a remessa ([UC-SHP-01](../../docs/use-cases/UC-SHP-01-create-shipment.md)): um volume por item do pedido (peso unitário vezes a quantidade, e as unidades empilhadas na altura), transportadora pela corrente, código de rastreio, remessa em `created` e `ShipmentCreated` na outbox |
| `order.cancelled` de pedido pago | cancela a remessa que ainda está no CD ([UC-SHP-09](../../docs/use-cases/UC-SHP-09-cancel-shipment.md)) e grava `ShipmentCancelled` na outbox; sem remessa ainda, registra o pedido em `cancelled_orders`, e um `order.paid` que chegue depois (replay da DLQ) não despacha |
| `order.cancelled` de pedido não pago | ignorado sem tocar no banco: pedido que não foi pago nunca teve remessa |
| os outros | ignorados |

A marca na inbox, a remessa com os volumes e o histórico, e o evento na outbox entram na mesma transação. Evento repetido encontra a marca e não muda nada; o `UNIQUE (order_id)` de `shipments` é a última barreira.

Quando algo dá errado, a categoria do erro de domínio decide o destino da mensagem:

| Situação | Destino |
|---|---|
| evento ilegível (fora do contrato) | `dlq.logistics.order-intake` na hora, sem retry |
| produto ainda sem snapshot (`Unavailable`) | retry com backoff por até meio minuto, porque a cópia do catálogo pode estar chegando junto (os dois workers sobem ao mesmo tempo); se persistir, DLQ |
| nenhuma transportadora serve, CD desconhecido, remessa que já saiu do CD | DLQ na hora, com um warning no log, porque uma pessoa precisa olhar |
| banco ou outra falha de infraestrutura | retry com backoff; se persistir, DLQ |

O correlation id do pedido segue para o log e para o evento publicado, e o `causationid` do evento novo é o id do evento de pedido que o causou.

## A escolha da transportadora

A escolha ([UC-SHP-02](../../docs/use-cases/UC-SHP-02-choose-carrier.md)) é outra corrente de regras sobre a tabela `carriers`. Os limites de peso vêm da tabela; as regras ficam no código.

```mermaid
flowchart LR
  consignment["CD de origem, estado de destino e peso"] --> flag{"logistics.own-fleet-dispatch"}
  flag -- ligada --> own["OwnFleet<br/>mesmo estado e peso dentro do limite"]
  flag -- "desligada ou sem resposta" --> partners
  own -- não serve --> partners["RegularPartners<br/>menor limite que comporta o peso"]
  partners -- não serve --> heavy["HeavyFreight<br/>carga-pesada"]
  heavy -- não serve --> none["NoCarrierFits"]
```

- A frota própria (`tucano-express`) só entrega no estado do CD de origem e até o limite dela.
- Entre os parceiros regulares, leva o de menor limite que ainda comporta o peso; no empate, o menor código. Os caminhões grandes ficam livres para o que só eles levam.
- A flag é um ops toggle: desligada, ela tira o primeiro elo da corrente. Se o flagd não responder, o fallback também é desligada, porque parceiro entrega em qualquer lugar.

## Código de rastreio

O código é um Snowflake do `SnowflakeGenerator`, guardado em `BIGINT` e mostrado como `TX` mais 13 símbolos em Base32 de Crockford (`TX02PQRFBTW5G03`). O worker 11 é o PHP-FPM e o 12 é o `logistics-order-intake`, então o próprio código diz em que processo e em que milissegundo a remessa nasceu.

## Endpoints

| Rota | Pelo Kong | O que faz |
|---|---|---|
| `GET /health/live` | `/api/logistics/health/live` | o processo está de pé |
| `GET /health/ready` | `/api/logistics/health/ready` | PostgreSQL e Redis respondem, com a latência de cada um |

Erros saem como `application/problem+json` (RFC 9457), no mesmo formato do commerce.

## Workers

Os workers usam a mesma imagem da API, cada um com um comando de longa duração, e param com `SIGTERM` depois de terminar a mensagem atual. O compose declara `stop_signal: SIGTERM` porque a imagem base herda o `SIGQUIT` do PHP-FPM.

| Serviço no compose | Comando | O que faz |
|---|---|---|
| `logistics-outbox-relay` | `php artisan logistics:relay-outbox` | publica a outbox em `logistics.shipments.v1` com `FOR UPDATE SKIP LOCKED`; a flag `chaos.logistics.outbox-relay-paused` pausa a publicação sem derrubar o processo |
| `logistics-catalog-sync` | `php artisan logistics:sync-catalog` | mantém peso e dimensões em `product_snapshots` a partir de `catalog.products.v1` ([UC-SHP-11](../../docs/use-cases/UC-SHP-11-sync-catalog.md)); snapshot ilegível vai para `dlq.logistics.catalog-sync` |
| `logistics-order-intake` | `php artisan logistics:order-intake` | cria e cancela remessas a partir de `commerce.orders.v1`; roda com `SNOWFLAKE_WORKER_ID=12` |

## Eventos publicados

| Evento | Contrato do `data` |
|---|---|
| `tucano.logistics.shipment.created` | [`logistics.shipment.created.schema.json`](../../contracts/events/logistics.shipment.created.schema.json) |
| `tucano.logistics.shipment.cancelled` | [`logistics.shipment.cancelled.schema.json`](../../contracts/events/logistics.shipment.cancelled.schema.json) |

O `ShipmentCreated` leva o destino só com cidade, estado e CEP. O tópico guarda os eventos por uma semana e nenhum consumidor precisa da rua nem do nome de quem recebe, então esses dados ficam no banco da logística. Os outros eventos da máquina já existem no domínio e ganham contrato quando os casos de uso deles entrarem.

## Rodando

```bash
make up
make check s=logistics   # Pint, Larastan nível 8, Deptrac e PHPUnit contra a stack
make logs s=logistics
```

O banco é o `logistics`, no mesmo PostgreSQL do commerce mas com role própria: o role `logistics` não consegue nem conectar no banco do commerce. É database-per-service numa instância compartilhada, com o isolamento garantido por permissão. Os testes rodam no `logistics_test`, para não apagar os dados da stack.

## Decisões do runtime

- **Snowflake no FPM**: cada filho do pool é um processo isolado, então a sequência fica no APCu (`ApcuSequence`). Workers de CLI usam `InMemorySequence`.
- **Flags**: `Flagd::connect` com cache em APCu no FPM e em memória no CLI. Ambiente desconhecido conta como produção.
- **Dependências passam pelo Toxiproxy** (`DB_HOST=toxiproxy`, `DB_PORT=15433`, `KAFKA_BROKERS=toxiproxy:19092`), para os experimentos de caos funcionarem sem mudar nada no serviço.
