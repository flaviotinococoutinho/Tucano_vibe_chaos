# commerce

Serviço de pedidos, estoque, pagamentos e notificações da Tucano, em Laravel 13 sobre PHP 8.4 (FPM). É o núcleo transacional: tudo que envolve dinheiro e estoque passa por aqui, com escrita ACID no PostgreSQL.

Cada pedido é de uma loja ([ADR 0031](../../docs/adr/0031-a-store-is-a-tenant.md)). O estoque, os centros de distribuição e o pagamento continuam da plataforma, chaveados pelo SKU e pelo pedido; a loja fica no pedido, na cópia local do catálogo e no read model da lista.

## Como está organizado

| Pasta | O que tem |
|---|---|
| `src/` | código de negócio, um pacote por subdomínio (package-by-feature), cada um com seu hexágono. Veja [`src/README.md`](src/README.md) |
| `app/` | só a cola com o Laravel: health checks, correlation id, problem details e o provider de plataforma |
| `config/platform.php` | flags, Snowflake e nome do serviço |
| `tests/Architecture` | fitness functions (casos de uso documentados) |
| `deptrac.yaml` | regra de dependência entre as camadas |

O Laravel serve de framework de entrega: roteamento, container de injeção, fila e console. Nada de Eloquent no domínio.

## Endpoints

| Rota | Pelo Kong | O que faz |
|---|---|---|
| `GET /health/live` | `/api/commerce/health/live` | o processo está de pé |
| `GET /health/ready` | `/api/commerce/health/ready` | PostgreSQL e Redis respondem, com a latência de cada um |
| `POST /v1/orders` | `/api/commerce/v1/orders` | faz um pedido numa loja ([UC-ORD-01](../../docs/use-cases/UC-ORD-01-place-order.md)): exige `store`, confere no catálogo local que cada item é um produto daquela loja, reserva o estoque ([UC-INV-01](../../docs/use-cases/UC-INV-01-reserve-stock.md)) e grava `OrderPlaced`, com a loja, na outbox; exige `Idempotency-Key` |
| `GET /v1/orders/{orderId}` | `/api/commerce/v1/orders/{orderId}` | consulta um pedido, com a loja e o histórico ([UC-ORD-05](../../docs/use-cases/UC-ORD-05-view-orders.md)); fica na borda para os laboratórios, com os dados pessoais mascarados |
| `GET /v1/stores/{store}/customers/{customerId}/orders` | não: a borda não tem essa rota | a lista de pedidos do cliente naquela loja, do mais novo para o mais velho, lida do read model `order_views` ([UC-ORD-05](../../docs/use-cases/UC-ORD-05-view-orders.md)); `?page=1&perPage=10`, com `perPage` de 1 a 50 |
| `GET /v1/stores/{store}/customers/{customerId}/orders/{orderId}` | não: a borda não tem essa rota | o pedido do cliente naquela loja, com o histórico, lido do PostgreSQL; o pedido de outra loja ou de outro cliente responde o mesmo `404` de um pedido que não existe |
| `POST /v1/orders/{orderId}/payments` | `/api/commerce/v1/orders/{orderId}/payments` | paga um pedido ([UC-PAY-01](../../docs/use-cases/UC-PAY-01-pay-order.md)): `202` com o pagamento pendente, ou `503` com `Retry-After` quando o circuito do PSP está aberto; exige `Idempotency-Key` |
| `POST /v1/webhooks/payfake` | `/api/commerce/v1/webhooks/payfake` | resultado da cobrança enviado pelo PayFake ([UC-PAY-02](../../docs/use-cases/UC-PAY-02-settle-payment.md)): assinatura HMAC conferida, evento repetido ignorado pela inbox, pedido pago ou cancelado na mesma transação |

O `POST /v1/orders` responde `201` com o pedido e um `Location` relativo (`orders/{id}`), que resolve certo tanto direto no serviço quanto atrás do prefixo `/api/commerce` do Kong. Repetir o request com a mesma `Idempotency-Key` devolve a mesma resposta com `Idempotent-Replayed: true`; a mesma chave com outro corpo, ou com os mesmos itens em outra loja, recebe `422`.

O corpo leva a loja em `store`, o slug dela (`arara`, `bemtevi`, `sabia`). Um item que não é produto dessa loja, porque é de outra ou porque a cópia do catálogo ainda não sabe a loja dele, é recusado com `422` no campo do item, no mesmo formato de qualquer outro campo errado, e nada é reservado:

```json
{
  "type": "about:blank",
  "title": "Unprocessable Content",
  "status": 422,
  "detail": "HOME-MUG-001 is not a product of arara.",
  "instance": "/v1/orders",
  "correlationId": "0199a2b4-a032-7e73-b2f4-5a6b7c8d9e0f",
  "errors": {
    "items.1.sku": ["HOME-MUG-001 is not a product of arara."]
  }
}
```

O commerce não consulta o catálogo para saber se a loja existe: a loja de cada produto na cópia local basta. Um produto que o catálogo não tem continua sendo `409` (`product-unavailable`), antes de qualquer conferência de loja.

Toda resposta com o pedido leva a loja em `store`, que só é `null` num pedido feito antes das lojas. As leituras de um pedido devolvem o corpo do `POST` mais o `history`: cada status por onde o pedido passou, do mais antigo ao mais novo, com a hora e o motivo de um cancelamento (`{"status": "cancelled", "at": "2026-09-29T06:29:36.772+00:00", "reason": "payment_declined"}`). O pedido e o histórico vêm do mesmo snapshot (`REPEATABLE READ`), então o status e o último passo concordam. A resposta do `POST` e a guardada para a `Idempotency-Key` continuam sem o histórico.

As rotas `/v1/stores/{store}/customers/{customerId}/orders` são do BFF, que manda a loja do endereço da tela e o id do perfil ativo como id do cliente ([ADR 0030](../../docs/adr/0030-each-customer-sees-only-its-orders.md), [ADR 0031](../../docs/adr/0031-a-store-is-a-tenant.md)). O commerce cobra o isolamento no próprio dado: uma loja só lê os próprios pedidos, e um cliente só os que fez. Um pedido de outra loja ou de outro cliente responde o mesmo `404` de um pedido que não existe, uma loja que não é um slug ou um id que não é UUIDv7 também responde `404`, e os pedidos de antes das lojas não aparecem em loja nenhuma. A borda nem chega a elas: o Kong alcança o commerce pela porta 8182 do nginx, que só serve as rotas públicas (pedidos, pagamentos, o webhook do PSP e o health) e responde `404` em problem details para o resto. O BFF usa a porta interna, a 8082, pela rede.

Todo erro sai como `application/problem+json` (RFC 9457) com o `correlationId` do request. Erros de domínio viram status pela categoria (`NotFound` 404, `Conflict` 409, `InvalidInput` 422, `Forbidden` 403, `Unavailable` 503) e não vão para o log de erro, porque são respostas esperadas, não incidentes.

## Read model

A lista de pedidos do cliente mora no MongoDB, na coleção `order_views` do banco `commerce_read`, com validador `$jsonSchema` e o índice `store_customer_history` (`store`, `customerId` e `placedAt`, em `database/mongo`). É o lado BASE do [ADR 0012](../../docs/adr/0012-acid-writes-base-reads.md): o `commerce-order-projector` alimenta a coleção a partir de `commerce.orders.v2` ([UC-ORD-08](../../docs/use-cases/UC-ORD-08-project-order-views.md)), então a lista pode ficar alguns segundos atrás do pedido, que continua sendo lido no PostgreSQL. Com o MongoDB fora, só a lista cai: `503` com `Retry-After: 5` e um warning no log; o checkout, o pedido e o pagamento seguem.

Cada documento guarda a loja do pedido, tirada do `order.placed`, e a lista de uma loja só lê as visões dela; uma visão de antes das lojas fica sem loja e fora de toda lista. Cada documento guarda também a versão do pedido (1 no `order.placed` e uma a mais a cada mudança de status), e uma mudança só vale para uma visão numa versão mais velha. Por isso um evento repetido, relido ou atrasado não muda nada, e um consumer group novo, lendo o tópico do começo, refaz a coleção inteira. O `order.placed` leva o nome de cada item desde esta versão; um evento de antes disso aparece na lista com o SKU no lugar do nome.

## Workers

Os workers usam a mesma imagem da API, cada um com um comando de longa duração, e param com `SIGTERM` depois de terminar o que estão fazendo. O compose declara `stop_signal: SIGTERM` porque a imagem base herda o `SIGQUIT` do PHP-FPM.

| Serviço no compose | Comando | O que faz |
|---|---|---|
| `commerce-outbox-relay` | `php artisan commerce:relay-outbox` | publica a outbox no Kafka com `FOR UPDATE SKIP LOCKED`; a flag `chaos.commerce.outbox-relay-paused` pausa a publicação sem derrubar o processo, e os eventos se acumulam na outbox até a flag voltar |
| `commerce-order-expiry` | `php artisan commerce:expire-orders` | cancela pedidos não pagos com a reserva vencida e devolve o estoque ([UC-ORD-03](../../docs/use-cases/UC-ORD-03-expire-unpaid-orders.md)); várias cópias dividem o trabalho com `SKIP LOCKED` |
| `commerce-payment-reconciler` | `php artisan commerce:reconcile-payments` | pergunta ao PSP pelos pagamentos sem desfecho há 60 s e aplica a resposta ([UC-PAY-03](../../docs/use-cases/UC-PAY-03-reconcile-payments.md)): webhook perdido, cobrança que nunca chegou ao PSP e estorno do dinheiro que chegou tarde ([UC-PAY-04](../../docs/use-cases/UC-PAY-04-refund-payment.md)) |
| `commerce-shipment-sync` | `php artisan commerce:sync-shipments` | o pedido acompanha a remessa a partir de `logistics.shipments.v2` ([UC-ORD-04](../../docs/use-cases/UC-ORD-04-follow-shipment.md)): coleta vira `shipped`, entrega vira `delivered` e devolução vira `returned`, com o pagamento pedindo o estorno na mesma transação |
| `commerce-catalog-sync` | `php artisan commerce:sync-catalog` | mantém `product_snapshots`, com a loja de cada produto, a partir de `catalog.products.v1` ([UC-ORD-06](../../docs/use-cases/UC-ORD-06-sync-catalog.md)); a mesma versão pode trazer a loja que a cópia ainda não sabia, e snapshot ilegível vai para `dlq.commerce.catalog-sync` |
| `commerce-order-projector` | `php artisan commerce:project-order-views` | mantém a lista de pedidos do cliente (`order_views`) a partir de `commerce.orders.v2`, no consumer group próprio `commerce.order-projector` ([UC-ORD-08](../../docs/use-cases/UC-ORD-08-project-order-views.md)). Com o MongoDB fora de alcance, espera sem limite; um evento ilegível vai direto para `dlq.commerce.order-projector`, e qualquer outra falha vai para lá depois das tentativas contadas. A flag `chaos.commerce.order-projector-paused` tira o projetor do grupo: a lista fica para trás de propósito e alcança os pedidos quando a flag desliga |

## Rodando

```bash
make up                  # sobe a stack com o commerce
make check s=commerce    # Pint, Larastan, Deptrac e PHPUnit contra a stack
make logs s=commerce
```

A configuração vem de variáveis de ambiente definidas no `compose.yaml`. O container roda `config:cache`, `route:cache` e `event:cache` na subida, e não no build, para não congelar o ambiente de quem construiu a imagem.

## Decisões do runtime

- **Snowflake no FPM**: cada filho do pool é um processo isolado, então a sequência fica no APCu (`ApcuSequence`). Workers de CLI usam `InMemorySequence`.
- **Flags**: `Flagd::connect` com cache em APCu no FPM e em memória no CLI. Ambiente desconhecido conta como produção.
- **Dependências passam pelo Toxiproxy** (`DB_HOST=toxiproxy`, `DB_PORT=15432`), para os experimentos de caos funcionarem sem mudar nada no serviço.
