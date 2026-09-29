# UC-ORD-07: Registrar o resultado do pagamento no pedido

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (Payments, dentro do UC-PAY-02) |
| **Escopo** | Commerce (Ordering) |
| **Gatilho** | o pagamento de um pedido foi aprovado ou recusado |

## Partes interessadas e interesses

- **Cliente**: pedido pago segue para a entrega; pedido recusado libera o que segurava.
- **Tucano**: o pagamento e a expiração nunca decidem o mesmo pedido ao mesmo tempo.

## Pré-condições

- A transação do pagamento está aberta, e o registro entra nela.

## Garantias mínimas

- A linha do pedido fica travada do começo ao fim: o job de expiração (UC-ORD-03) espera ou pula.

## Garantias de sucesso

- Aprovado: pedido `paid`, estoque vendido (UC-INV-04) e `OrderPaid` na outbox, com tudo que o Logistics precisa para criar a remessa, inclusive a loja do pedido ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)).
- Recusado: pedido `cancelled` com `payment_declined`, estoque liberado (UC-INV-03) e `OrderCancelled` na outbox.

## Cenário principal de sucesso

1. O Payments informa o pedido e o resultado.
2. O sistema trava o pedido.
3. O pedido está esperando pagamento: o sistema aplica o resultado, grava a transição no histórico e o evento na outbox.

## Extensões

- 3a. Aprovado, mas o pedido já foi cancelado pela expiração: o sistema responde que chegou tarde, e o Payments pede o estorno.
- 3b. Recusado, mas o pedido já foi cancelado: nada a desfazer.
- 3c. Aprovado para um pedido já pago, enviado ou entregue: isso não pode acontecer (um pagamento pendente e um aprovado por pedido, os dois garantidos pelo banco), então o sistema falha alto em vez de estornar em silêncio.

## No código

- Port `ForSettlingOrderPayments`, caso de uso `SettleOrderPayment`, pacote `Commerce\Ordering`. O Payments chega nele pelo adapter `OrderingSettlements`.
