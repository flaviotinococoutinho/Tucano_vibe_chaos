# UC-SHP-09: Cancelar a remessa

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (evento `tucano.commerce.order.cancelled`) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | o cancelamento de um pedido pago chega ao consumer group `logistics.order-intake` |

## Partes interessadas e interesses

- **Cliente**: não receber um pedido que cancelou.
- **Tucano**: não despachar o volume de um pedido estornado e, se ele já saiu, ficar sabendo na hora.
- **Transportadoras**: não passar no CD para coletar o que foi cancelado.

## Pré-condições

- Nenhuma. Um pedido cancelado antes do pagamento nunca teve remessa, e o consumidor ignora o evento dele.

## Garantias mínimas

- Evento repetido não cancela duas vezes nem publica dois eventos (inbox).
- Remessa que já saiu do CD não muda de estado por causa do cancelamento.

## Garantias de sucesso

- Remessa `cancelled`, com a linha do histórico (motivo `order_cancelled`) e `ShipmentCancelled` na outbox, na mesma transação da marca na inbox.

## Cenário principal de sucesso

1. O sistema recebe o cancelamento de um pedido que estava pago.
2. O sistema registra o evento na inbox.
3. O sistema busca a remessa do pedido e trava a linha até o fim da transação.
4. O sistema cancela a remessa pela máquina de estados.
5. O sistema grava o novo estado, a transição no histórico e `ShipmentCancelled` na outbox.

## Extensões

- 1a. Pedido cancelado enquanto esperava pagamento (`previousStatus` igual a `pending_payment`): o sistema ignora o evento sem tocar no banco. É o caso mais comum, o das reservas que expiram.
- 2a. Evento já processado: o sistema ignora e confirma o offset.
- 3a. O pedido ainda não tem remessa (o `order.paid` dele foi para a DLQ, por exemplo): não há nada a cancelar, mas o sistema registra o pedido em `cancelled_orders`. Se alguém reprocessar aquele `order.paid` depois, ele chega fora de ordem e não despacha um pedido cancelado (UC-SHP-01, extensão 1b).
- 4a. A remessa já saiu do CD (`picked_up` em diante): a máquina de estados recusa com `TransitionNotAllowed` e nada muda. O sistema registra um warning e manda a mensagem direto para `dlq.logistics.order-intake`, sem retry, porque uma pessoa precisa trazer os volumes de volta.
- \*a. O banco cai: a transação volta inteira, inclusive a marca na inbox. A mensagem espera o banco voltar, sem ir para a DLQ, e a partição espera com ela.

## Variações de tecnologia

- A busca usa `SELECT ... FOR UPDATE`. Uma mudança concorrente, como a etiqueta ficando pronta, espera o cancelamento terminar em vez de falhar na checagem de versão.
- Cancelar depois da coleta não é compensado aqui. Por enquanto vira trabalho de uma pessoa, a partir da DLQ.

## No código

- Port `ForCancellingShipments`, caso de uso `CancelShipment`, pacote `Logistics\Shipping`. O consumidor Kafka é o adapter `OrderEventHandler`, rodando no comando `logistics:order-intake`.
