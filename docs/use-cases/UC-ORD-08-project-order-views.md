# UC-ORD-08: Manter a lista de pedidos do cliente

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (os eventos de `commerce.orders.v2`) |
| **Escopo** | Commerce (Ordering) |
| **Gatilho** | chega um evento de pedido no tópico |

## Partes interessadas e interesses

- **Cliente**: achar na lista de cada loja todos os pedidos que fez nela, cada um com o status de agora, poucos segundos depois de cada mudança.
- **Loja**: ter na lista só os próprios pedidos ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)).
- **Tucano**: servir a lista sem consultar o PostgreSQL dos pedidos, e deixar uma queda do MongoDB longe do checkout e da tela do pedido.

## Pré-condições

- Nenhuma. Um consumer group novo lê o tópico desde o começo e monta a lista inteira, até onde a retenção de 7 dias do tópico alcança.

## Garantias mínimas

- A lista nunca volta no tempo: um evento repetido, relido ou atrasado não muda uma visão que já está numa versão igual ou mais nova.
- Uma queda do MongoDB não manda mensagem boa para a DLQ: a partição espera.
- Uma visão nunca muda de loja: a loja vem do `order.placed` e fica.

## Garantias de sucesso

- `order_views` tem um documento por pedido, com o número, a loja, o cliente, o status, o motivo de um cancelamento, o total, os itens com nome e quantidade, a hora em que o pedido foi feito e a hora da última mudança.

## Cenário principal de sucesso

1. O Commerce publica um evento de pedido em `commerce.orders.v2`.
2. O projetor lê o evento no seu próprio consumer group, o `commerce.order-projector` ([ADR 0027](../adr/0027-one-consumer-group-per-read-model.md)).
3. O `order.placed` abre a visão do pedido em `pending_payment`, com a loja, o cliente, os itens e o total. A hora do evento vira o `placedAt`.
4. O `order.paid`, o `order.shipped`, o `order.delivered`, o `order.returned` e o `order.cancelled` levam a visão ao status do evento. A hora do evento vira o `updatedAt`, e o cancelamento deixa o motivo.

## Extensões

- *a. O MongoDB está fora de alcance (nenhum servidor para selecionar, conexão recusada ou caída): a partição espera sem limite, com backoff, e segue quando ele volta ([ADR 0017](../adr/0017-wait-for-the-database-not-the-dlq.md)).
- *b. O MongoDB responde com um erro (um documento fora do validador, que só um defeito do código produz): as tentativas são contadas, com backoff, e depois a mensagem vai para `dlq.commerce.order-projector`, onde uma pessoa olha.
- 1a. O Kafka está fora: nenhum evento chega, e a lista para no tempo até ele voltar. O checkout e a tela do pedido seguem.
- 2a. A flag de caos `chaos.commerce.order-projector-paused` está ligada: o projetor sai do grupo entre duas mensagens e espera. Os pedidos continuam, e a lista fica para trás de propósito. Quando a flag desliga, o grupo volta do offset que confirmou e alcança o que ficou para trás. É o laboratório de consistência que o [ADR 0012](../adr/0012-acid-writes-base-reads.md) prometia.
- 2b. Evento ilegível (um id que não é UUIDv7, um pedido sem itens, um cancelamento a partir de um status que não se cancela, uma loja que não é um slug): vai direto para `dlq.commerce.order-projector`, sem retry, porque tentar de novo não conserta.
- 2c. Evento de outro tipo no tópico: o projetor ignora.
- 3a. O evento é de antes de o `order.placed` levar o nome dos itens: a lista mostra o SKU no lugar do nome.
- 3b. A visão já existe (entrega repetida, grupo lendo de novo): nada muda.
- 3c. O `order.placed` é de antes das lojas, sem `store`: a visão fica sem loja, segue o pedido como qualquer outra, e nenhuma lista a mostra. Um consumer group novo que lê o tópico do começo refaz essas visões assim, sem loja.
- 4a. O evento é repetido, ou mais velho que a visão (um replay da DLQ, por exemplo): nada muda.
- 4b. Não há visão para mudar, porque o `order.placed` do pedido nunca chegou ao projetor (saiu do tópico pela retenção antes de o grupo ler, ou foi para a DLQ): nada muda, e o log registra um warning. O pedido continua certo no PostgreSQL; só fica fora da lista. Para refazer a lista, basta ler o tópico de novo com o grupo.

## Variações de tecnologia

- Os eventos de um pedido usam o id dele como chave no Kafka e caem na mesma partição, então chegam na ordem em que o Commerce publicou. A versão cuida do resto: cada evento diz sozinho a versão que o pedido tem depois dele, a mesma conta do `version` do agregado. O `placed` é 1, o `paid` é 2, o `shipped` é 3, o `delivered` e o `returned` são 4, e o cancelamento é um a mais que o status em que o pedido estava, que o `order.cancelled` traz em `previousStatus`.
- A escrita é condicional e dispensa inbox: o `order.placed` é um insert, que a chave duplicada recusa, e cada mudança de status é um update com `version` menor que a nova no filtro.
- Uma mudança de status nunca cria a visão, porque o validador `$jsonSchema` da coleção exige o cliente e os itens, e só o `order.placed` traz os dois.
- A loja vem só do `order.placed`. Os outros eventos também a trazem, mas a loja de um pedido nunca muda, então uma mudança de status não mexe nela. O validador aceita a loja como slug (`^[a-z][a-z0-9-]{1,30}$`) ou nula, e não a exige, por causa das visões de antes das lojas. A lista lê pelo índice `store_customer_history` (`store`, `customerId` e `placedAt` decrescente), que tomou o lugar do `customer_history`, porque nenhuma leitura vai mais só pelo cliente. As duas coisas estão na migration `2026_09_29_000100_add_store_to_order_views`; como o `collMod` troca o validador inteiro, ela repete o esquema da primeira, com a loja.
- O campo `shipment` fica `null` por enquanto. A remessa entra na lista num passo seguinte, lida de `logistics.shipments.v2`.

## No código

- Port `ForProjectingOrderViews`, caso de uso `ProjectOrderView`, pacote `Commerce\Ordering`. O consumidor é o `OrderViewProjector`, rodando no comando `commerce:project-order-views` (o serviço `commerce-order-projector` do compose), e o read model é o `MongoOrderViews`, o mesmo que a lista do [UC-ORD-05](UC-ORD-05-view-orders.md) lê. A versão de cada status vem do `OrderStatus::orderVersion`, e o `StatusMove` leva a mudança até a visão.
