# UC-PAY-02: Informar o resultado de um pagamento

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | PayFake (webhook), ou a conciliação (UC-PAY-03) quando o webhook se perde |
| **Escopo** | Commerce (Payments) |
| **Gatilho** | o PSP termina de processar uma cobrança ou um estorno e envia o webhook |

## Partes interessadas e interesses

- **Cliente**: ver o pedido pago logo depois de pagar, ou saber na hora que o cartão foi recusado.
- **Tucano**: nunca confirmar pedido sem dinheiro, nunca vender a mesma unidade duas vezes, e devolver o dinheiro que chegou tarde.
- **PayFake**: saber quando pode parar de reenviar.

## Pré-condições

- O pagamento existe: o id dele foi a `Idempotency-Key` e a `reference` da cobrança.

## Garantias mínimas

- Um evento é processado uma vez só, mesmo chegando repetido (inbox).
- Webhook com assinatura inválida não muda nada.
- Ou tudo que o resultado muda acontece junto, ou nada acontece e o PSP reenvia.

## Garantias de sucesso

- Cobrança aprovada: pagamento `captured`, pedido `paid`, reserva convertida em venda (UC-INV-04) e `OrderPaid` na outbox.
- Cobrança recusada: pagamento `failed` com o motivo, pedido `cancelled` com `payment_declined` e estoque de volta à venda.
- Estorno concluído: pagamento `refunded`.

## Cenário principal de sucesso

1. O PayFake envia o evento com o header `PayFake-Signature`.
2. O sistema confere a assinatura sobre o corpo cru, com tolerância de 5 minutos no horário.
3. O sistema traduz o evento do PayFake para o resultado do pagamento (camada anticorrupção).
4. O sistema registra o id do evento na inbox, trava o pagamento e anota o id da cobrança.
5. O sistema aplica o resultado no pagamento e no pedido (UC-ORD-07), na mesma transação.
6. O sistema responde `200`, e o PayFake para de reenviar.

## Extensões

- 2a. Assinatura inválida, velha ou malformada: `400` e um warning no log.
- 3a. Tipo de evento que o Payments não lê: `200` com `ignored`.
- 4a. Evento repetido: a inbox já tem o id; `200` com `duplicate`.
- 4b. Referência que não é um pagamento daqui: `200` com `unknown_payment`.
- 5a. O pagamento já tem a palavra final (uma cópia tardia de outro evento): nada muda; `200` com `already_settled`.
- 5b. Cobrança aprovada depois de o pedido expirar: o pagamento vai para `refund_requested`, e o estorno (UC-PAY-04) devolve o dinheiro.
- 5c. Resultado de um pagamento `abandoned` (a conciliação desistiu dele, UC-PAY-03): a captura vai direto para `refund_requested`, porque o pedido já foi cancelado; a recusa só fica registrada.
- \*a. O banco falha no meio: tudo volta, o webhook responde `5xx` e o PayFake reenvia com backoff.

## Variações de tecnologia

- Sem `Idempotency-Key`: o id do evento, na inbox, faz esse papel.
- O corpo é verificado byte a byte, antes de qualquer decodificação: decodificar e codificar de novo mudaria o HMAC.
- O webhook empurra o resultado e a conciliação puxa; os dois passam pelo mesmo caso de uso e pela mesma inbox, então um resultado é aplicado de um jeito só, venha por onde vier.

## No código

- Port `ForSettlingPayments`, caso de uso `SettlePayment`, pacote `Commerce\Payments`. O webhook é o `PayFakeWebhookController`, com `PayFakeSignature` e o tradutor `PayFakeEvents`.
