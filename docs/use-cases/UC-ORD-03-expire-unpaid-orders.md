# UC-ORD-03: Expirar pedidos não pagos

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Relógio (o worker `commerce-order-expiry`) |
| **Escopo** | Commerce (Ordering) |
| **Gatilho** | a reserva de um pedido `pending_payment` passou de `reservationExpiresAt` |

## Partes interessadas e interesses

- **Cliente que não pagou**: nada a perder; o pedido some da lista de pendências.
- **Outros clientes**: o estoque preso por quem desistiu volta para venda.
- **Tucano**: não segurar estoque de graça e não cancelar pedido que acabou de ser pago.

## Pré-condições

- Nenhuma. O worker procura sozinho, a cada poucos segundos.

## Garantias mínimas

- Um pedido nunca é cancelado duas vezes, mesmo com vários workers rodando.
- Pedido que já foi pago não é tocado.

## Garantias de sucesso

- Pedido `cancelled` com motivo `reservation_expired`, as reservas `released`, as unidades de volta em `stock_items` e `OrderCancelled` na outbox, tudo na mesma transação.

## Cenário principal de sucesso

1. O sistema pega o pedido não pago com a reserva vencida há mais tempo, travando a linha.
2. O sistema cancela o pedido pela máquina de estados.
3. O sistema libera o estoque do pedido (UC-INV-03).
4. O sistema grava o novo estado, a transição no histórico e o evento na outbox.
5. O sistema repete a partir do passo 1 até não sobrar pedido vencido, e então espera alguns segundos.

## Extensões

- 1a. Outro worker está com o pedido: o `SKIP LOCKED` pula a linha e pega o próximo; cada pedido vai para um worker só.
- 1b. O pagamento chega ao mesmo tempo: quem travar a linha primeiro decide. Se a expiração ganhar, o pagamento encontra o pedido cancelado e é estornado (UC-PAY-01, extensão 5b).
- 4a. A versão do pedido mudou desde a leitura: o sistema desfaz tudo e tenta de novo na próxima volta.
- \*a. O banco falha: o worker registra o erro, espera dois segundos e continua; o pedido fica para a próxima volta.

## Variações de tecnologia

- Uma transação por pedido. Um pedido problemático não trava os outros, e o lock dura só o tempo de um cancelamento.
- A busca usa o índice parcial `orders_awaiting_payment_idx` (`WHERE status = 'pending_payment'`), que tem o tamanho das pendências e não do histórico.

## No código

- Port `ForExpiringOrders`, caso de uso `ExpireOrders`, pacote `Commerce\Ordering`. O laço é o comando `commerce:expire-orders`.
