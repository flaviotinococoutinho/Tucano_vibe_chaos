# UC-SHP-01 · Criar a remessa de um pedido pago

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (evento `tucano.commerce.order.paid`) |
| **Escopo** | Logistics |
| **Gatilho** | o evento de pedido pago chega ao grupo `logistics.order-intake` |

## Partes interessadas e interesses

- **Cliente**: que o envio comece logo depois do pagamento.
- **Logística**: uma única remessa por pedido, mesmo que o evento chegue repetido.
- **Transportadoras**: receber só o que conseguem levar (peso e tamanho).

## Pré-condições

- O snapshot dos produtos (peso e dimensões) já chegou pelo tópico do catálogo.

## Garantias mínimas

- Evento repetido não cria uma segunda remessa (inbox + `UNIQUE (order_id)`).

## Garantias de sucesso

- Remessa `created` com código de rastreio Snowflake, transportadora escolhida, etiqueta enfileirada e `ShipmentCreated` na outbox.

## Cenário principal de sucesso

1. O sistema recebe o pedido pago com os itens, o CD de origem e o endereço de entrega.
2. O sistema monta os volumes a partir do peso e das dimensões de cada produto.
3. O sistema escolhe a transportadora pela corrente de regras (UC-SHP-02).
4. O sistema gera o código de rastreio, cria a remessa em `created` e registra `ShipmentCreated` na outbox.
5. O sistema enfileira a geração da etiqueta (UC-SHP-03).

## Extensões

- 1a. Evento já processado: o sistema ignora e confirma o offset.
- 2a. Produto ainda sem snapshot: a mensagem volta para nova tentativa; se persistir, vai para `dlq.logistics.order-intake`.
- 5a. Fila de etiquetas indisponível: a remessa fica em `created` e uma varredura periódica reenfileira as etiquetas pendentes.

## No código

- Port `ForCreatingShipments`, caso de uso `CreateShipment`, pacote `Logistics\Shipping`.
