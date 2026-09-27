# UC-PAY-01: Pagar um pedido

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente |
| **Escopo** | Commerce (Payments) |
| **Gatilho** | o cliente confirma o pagamento de um pedido `pending_payment` |

## Partes interessadas e interesses

- **Cliente**: ser cobrado uma única vez, mesmo com clique duplo ou rede instável.
- **Tucano**: não confirmar pedido sem dinheiro e não travar o checkout quando o PSP estiver lento.
- **PayFake**: receber requisições com chave de idempotência.

## Pré-condições

- Pedido em `pending_payment` com a reserva de estoque ainda válida.

## Garantias mínimas

- No máximo uma cobrança efetiva por pagamento: a `Idempotency-Key` enviada ao PSP é o id do pagamento.
- Se o PSP não responder, o pagamento fica `pending` e a conciliação (UC-PAY-03) resolve depois.

## Garantias de sucesso

- Pagamento `captured`, pedido `paid`, estoque baixado e `OrderPaid` na outbox.

## Cenário principal de sucesso

1. O cliente informa o meio de pagamento (token do cartão).
2. O sistema cria o pagamento `pending` e envia a cobrança ao PSP com timeout e `Idempotency-Key`.
3. O PSP aceita a cobrança para processamento e o sistema responde `202 Accepted`.
4. O PSP informa o resultado por webhook assinado (UC-PAY-02).
5. O sistema valida a assinatura, ignora duplicatas e, na mesma transação, marca o pagamento como `captured`, o pedido como `paid` e confirma a reserva de estoque.

## Extensões

- 1a. Pedido desconhecido: `404`. Pedido que não está aguardando pagamento, ou com a reserva vencida: `409`.
- 2a. Circuit breaker aberto (PSP instável): o sistema responde `503` com `Retry-After`, sem chamar o PSP e sem criar pagamento, para que a nova tentativa seja uma tentativa de verdade.
- 2b. Timeout: o pagamento fica `pending` e o sistema responde `202`. Repetir o request com a mesma chave reenvia a cobrança com o mesmo id, e o PSP devolve a cobrança que já tinha.
- 2c. Outra tentativa chega enquanto o pedido tem um pagamento pendente (com outra chave): as duas compartilham o pagamento, e o PSP vê uma chave só.
- 4a. Webhook duplicado: ignorado pela inbox.
- 4b. Webhook perdido: a conciliação consulta o PSP (UC-PAY-03).
- 5a. Pagamento recusado: pagamento `failed`, pedido `cancelled` e reserva liberada.
- 5b. Pagamento aprovado depois de a reserva expirar: o pedido já está `cancelled`, então o sistema estorna (UC-PAY-04).

## Variações de tecnologia

- A chamada ao PSP acontece fora de transação: nenhuma linha fica travada enquanto a rede decide.
- Um pagamento pendente por pedido é regra do banco (índice único parcial `WHERE status = 'pending'`), e a criação usa `INSERT ... ON CONFLICT DO NOTHING` nesse índice.
- O circuit breaker guarda o estado no Redis, porque o PHP-FPM não guarda nada entre requests. O experimento está no [laboratório de circuit breaker](../labs/circuit-breaker.md).

## No código

- Port `ForPayingOrders`, caso de uso `PayOrder`, pacote `Commerce\Payments`. O PSP é o port `ForChargingCards`: o adapter `PayFakeGateway` (a camada anticorrupção) atrás do decorator `BreakerGuardedGateway`.
