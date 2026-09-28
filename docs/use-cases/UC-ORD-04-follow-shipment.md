# UC-ORD-04: Refletir o andamento da remessa no pedido

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Logistics (os eventos de `logistics.shipments.v2`) |
| **Escopo** | Commerce (Ordering) |
| **Gatilho** | a remessa de um pedido é coletada, entregue ou devolvida ao CD |

## Partes interessadas e interesses

- **Cliente**: ver no pedido o que aconteceu com a encomenda, e ter o dinheiro de volta quando ela volta para o CD.
- **Tucano**: o pedido e a remessa contam a mesma história, sem o commerce ler o banco da logística.
- **Atendimento**: um pedido devolvido nunca fica com o pagamento.

## Pré-condições

- O pedido está pago e tem uma remessa na logística (UC-SHP-01).

## Garantias mínimas

- Cada evento da remessa muda o pedido uma vez só: a inbox guarda o id do evento.
- Um pedido devolvido e o pedido de estorno do pagamento dele entram na mesma transação.

## Garantias de sucesso

- A coleta leva o pedido para `shipped`, a entrega para `delivered` e a devolução para `returned`, cada passo com o histórico e o evento em `commerce.orders.v2`.
- Na devolução, o pagamento capturado do pedido fica `refund_requested`, e a conciliação manda o estorno ao PSP (UC-PAY-04).

## Cenário principal de sucesso

1. O consumer group `commerce.shipment-sync` lê o evento da remessa em `logistics.shipments.v2`.
2. O sistema marca o evento na inbox e trava o pedido.
3. O sistema move o pedido: `shipment.picked_up` para `shipped`, `shipment.delivered` para `delivered`, `shipment.returned` para `returned`.
4. O sistema grava o pedido e o evento dele na outbox, na mesma transação da marca na inbox.

## Extensões

- 1a. Os outros passos da jornada (hub, saída para entrega, visita que falhou, volta ao remetente) não mudam o pedido: o consumidor ignora esses eventos.
- 2a. Evento repetido: a inbox descarta, e nada muda.
- 3a. Devolução: na mesma transação, o Payments marca o pagamento capturado do pedido como `refund_requested`. Sem pagamento capturado (nunca capturado, ou já estornando), um warning registra o caso.
- 3b. O pedido não aceita o passo (pedido desconhecido, ou que seguiu outro caminho): o evento vai para `dlq.commerce.shipment-sync`, com um warning para uma pessoa olhar.
- 4a. Banco fora do ar: a partição espera o banco voltar, e nada vai para a DLQ por isso ([ADR 0017](../adr/0017-wait-for-the-database-not-the-dlq.md)).

## Variações de tecnologia

- Os eventos de uma remessa usam o id dela como chave no Kafka e caem na mesma partição, então chegam na ordem em que a logística publicou.
- O pedido de estorno passa do Ordering para o Payments pelo port de entrada do Payments, por um adapter do lado do Ordering (`PaymentsRefunds`), como a liquidação do pagamento faz no sentido contrário.

## No código

- Port `ForFollowingShipments`, caso de uso `FollowShipment`, pacote `Commerce\Ordering`. O consumidor é o `ShipmentEventHandler`, rodando no `commerce:sync-shipments`. O estorno passa pelo port `ForRefundingOrders` (adapter `PaymentsRefunds`) até o `ForRequestingRefunds` do Payments (caso de uso `RequestOrderRefund`).
