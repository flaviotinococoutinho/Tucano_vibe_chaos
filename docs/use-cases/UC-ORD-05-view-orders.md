# UC-ORD-05: Consultar os próprios pedidos

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente (pelo BFF, com o perfil ativo) |
| **Escopo** | Commerce (Ordering) |
| **Gatilho** | o cliente abre "Meus pedidos" ou um pedido |

## Partes interessadas e interesses

- **Cliente**: ver o pedido que acabou de fazer, com status, itens, total, prazo para pagar e o que aconteceu com ele até aqui; achar numa lista todos os pedidos que fez; e nunca ver o pedido de outra pessoa.
- **Tucano**: responder o pedido no mesmo formato da criação, sem um segundo formato para manter, e servir a lista sem pesar no banco dos pedidos.

## Pré-condições

- O BFF sabe quem é o cliente: o perfil ativo da sessão assinada ([ADR 0030](../adr/0030-each-customer-sees-only-its-orders.md)). O navegador nunca escolhe o id do cliente.
- Para abrir um pedido, o cliente tem o id dele, que veio na resposta do UC-ORD-01 (header `Location`) ou na lista.

## Garantias mínimas

- Um cliente só lê os próprios pedidos. O pedido de outro cliente responde o mesmo `404` de um pedido que não existe, palavra por palavra, sem dizer que ele existe.
- Um id de cliente ou de pedido que nem é um UUIDv7 responde `404` e não diz mais nada.
- As rotas por cliente não saem na borda: o Kong encerra `/api/commerce/v1/customers` com um `404`, e só o BFF, na rede interna, chega nelas.
- Com o MongoDB fora, só a lista cai. O pedido, o checkout e o pagamento seguem.

## Garantias de sucesso

- O pedido volta no mesmo formato da resposta do UC-ORD-01, com o status atual e o histórico em `history`: cada status por onde o pedido passou, do mais antigo ao mais novo, com a hora (`at`, UTC com milissegundos) e o motivo (`reason`), que só um cancelamento tem.
- Um pedido cancelado diz por quê em `cancellationReason`: `payment_declined` (o cartão foi recusado), `reservation_expired` (o prazo para pagar acabou) ou `customer_request`. Nos outros status, ele vem `null`. É o que deixa a tela de "confirmando o pagamento" terminar com a notícia certa quando o cartão é recusado, sem uma leitura de pagamentos só para isso.
- Depois da coleta, `trackingCode` traz o código da remessa, que abre a página de rastreio (UC-SHP-10). Antes disso ele vem `null`, e também fica `null` nos pedidos que saíram antes de o pedido guardar o código: a logística tem esses códigos, o pedido não.
- A lista traz os pedidos do cliente do mais novo para o mais velho, uma página por vez, com o total de pedidos. Cada pedido vem com o número, o status, o motivo de um cancelamento, o total, os itens (SKU, nome e quantidade), a hora em que foi feito e a da última mudança.

## Cenário principal de sucesso

1. O cliente abre "Meus pedidos".
2. O BFF pede `GET /v1/customers/{id do perfil}/orders?page=1&perPage=10`.
3. O sistema lê a página no read model `order_views` e responde com `page`, `perPage`, `total` e `orders`.
4. O cliente abre um pedido da lista.
5. O BFF pede `GET /v1/customers/{id do perfil}/orders/{id do pedido}`.
6. O sistema lê o pedido e o histórico dele no PostgreSQL, confere que o pedido é do cliente e devolve o estado atual com o histórico.

## Extensões

- 2a. `page` menor que 1, `perPage` fora de 1 a 50, ou um dos dois que não é um número inteiro: `422` com os erros por campo. Sem os dois, a página é a 1, com 10 pedidos.
- 3a. Um cliente que ainda não fez pedido: a lista vem vazia, com `total` 0.
- 3b. Um pedido feito agora ainda não está na lista: o read model fica alguns segundos atrás dos pedidos, de propósito, e a tela avisa. Com o Kafka fora, ou com a flag `chaos.commerce.order-projector-paused` ligada, a lista para no tempo até o projetor voltar ([UC-ORD-08](UC-ORD-08-project-order-views.md)).
- 3c. O MongoDB está fora de alcance: `503` com `Retry-After: 5`, e um warning no log. A leitura tenta uma vez só: desiste na hora quando a conexão é recusada, e no limite dos timeouts do driver (`MONGO_CONNECT_TIMEOUT_MS` e `MONGO_SOCKET_TIMEOUT_MS`) quando o MongoDB não responde.
- 3d. Um id de cliente que nem é um UUIDv7: `404`.
- 6a. Pedido desconhecido, ou de outro cliente: `404`, o mesmo nos dois casos.
- 6b. O pedido muda enquanto é lido: o pedido e o histórico vêm do mesmo snapshot, então o status e o último passo do histórico concordam.

## Variações de tecnologia

- O pedido é lido no PostgreSQL, e não no read model do MongoDB, de propósito: quem acabou de pedir precisa ver o pedido na hora (read-your-writes), antes de qualquer projeção. O histórico sai da tabela append-only `order_status_transitions`, na mesma transação `REPEATABLE READ` do pedido.
- A lista é BASE ([ADR 0012](../adr/0012-acid-writes-base-reads.md)): lê o `order_views`, que o UC-ORD-08 mantém a partir de `commerce.orders.v2`, pelo índice `customer_history` (`customerId` e `placedAt`). A mesma informação, lida forte de um lado e eventual do outro, é o laboratório de consistência.
- A rota `GET /v1/orders/{id}`, sem cliente, continua na borda para os laboratórios. Ela devolve o mesmo corpo, com o histórico, e com os dados pessoais mascarados, como antes. O `POST /v1/orders` e a resposta guardada para a `Idempotency-Key` não levam o histórico.
- Os dados pessoais saem mascarados em todas as leituras (nome e e-mail de quem comprou), como na resposta do UC-ORD-01.

## No código

- O pedido: port `ForViewingOrders` (`viewOrder` e `viewCustomerOrder`), caso de uso `ViewOrder`, que pergunta ao agregado `Order` se foi aquele cliente que o fez (`isPlacedBy`). Os controllers são o `ViewOrderController` e o `ViewCustomerOrderController`.
- A lista: port `ForListingOrders`, caso de uso `ListOrders`, que lê o read model pelo port `ForReadingOrderViews` (adapter `MongoOrderViews`). O controller é o `ListOrdersController`, com a validação da página no `ListOrdersRequest`, e a queda do MongoDB é o erro de domínio `OrderListUnavailable`, da categoria `Unavailable`.
- A borda: a rota `commerce-customers-internal` do `infra/kong/kong.yml`, com o plugin `request-termination`.
