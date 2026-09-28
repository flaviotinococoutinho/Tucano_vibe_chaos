# UC-SHP-01: Criar a remessa de um pedido pago

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (evento `tucano.commerce.order.paid`) |
| **Escopo** | Logistics |
| **Gatilho** | o evento de pedido pago chega ao consumer group `logistics.order-intake` |

## Partes interessadas e interesses

- **Cliente**: que o envio comece logo depois do pagamento.
- **Logística**: uma única remessa por pedido, mesmo que o evento chegue repetido.
- **Transportadoras**: receber só o que conseguem levar (peso e tamanho).

## Pré-condições

- O snapshot dos produtos (peso e dimensões) já chegou pelo tópico do catálogo.

## Garantias mínimas

- Evento repetido não cria uma segunda remessa (inbox + `UNIQUE (order_id)`).

## Garantias de sucesso

- Remessa `created` com código de rastreio Snowflake, transportadora escolhida e `ShipmentCreated` na outbox, na mesma transação da inbox.

## Cenário principal de sucesso

1. O sistema recebe o pedido pago com os itens, o CD de origem e o endereço de entrega.
2. O sistema monta os volumes a partir do peso e das dimensões de cada produto.
3. O sistema escolhe a transportadora pela corrente de regras (UC-SHP-02).
4. O sistema gera o código de rastreio, cria a remessa em `created` e registra `ShipmentCreated` na outbox.
5. O relay publica o `ShipmentCreated` em `logistics.shipments.v2`, e é dele que parte a geração da etiqueta (UC-SHP-03).

## Extensões

- 1a. Evento já processado: o sistema ignora e confirma o offset.
- 1b. O cancelamento do pedido chegou antes (um `order.paid` reprocessado da DLQ, por exemplo): o pedido está em `cancelled_orders`, e o sistema marca o evento na inbox sem criar remessa. O Kafka mantém a ordem dos eventos de um pedido, mas o replay não; por isso a ordem de chegada não pode mudar o resultado.
- 2a. Produto ainda sem snapshot: a mensagem entra em retry por cerca de 30 s (8 tentativas, de 500 ms até 10 s de espera), porque o pedido pode chegar antes da cópia do catálogo quando os dois workers sobem juntos. Se o problema persistir, ela vai para `dlq.logistics.order-intake`.
- 3a. Nenhuma transportadora atende os volumes: recusa do domínio, que tentar de novo não resolve. A mensagem vai direto para a DLQ, com um warning no log para uma pessoa olhar.

## No código

- Port `ForCreatingShipments`, caso de uso `CreateShipment`, pacote `Logistics\Shipping`.
