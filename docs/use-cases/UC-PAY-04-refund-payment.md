# UC-PAY-04: Estornar um pagamento

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (a conciliação, UC-PAY-03) |
| **Escopo** | Commerce (Payments) |
| **Gatilho** | a conciliação encontra um pagamento `refund_requested` com a cobrança `succeeded` no PSP |

## Partes interessadas e interesses

- **Cliente**: ter o dinheiro de volta, uma vez só, quando pagou por um pedido que não vai acontecer.
- **Tucano**: não ficar com dinheiro de pedido cancelado e não estornar duas vezes.
- **PayFake**: receber o estorno com chave de idempotência.

## Pré-condições

- O pagamento está `refund_requested` e tem o id da cobrança. Hoje ele chega assim de dois jeitos: a captura depois de o pedido expirar (UC-PAY-02, extensão 5b) e a captura de um pagamento `abandoned` (UC-PAY-03, extensão 4c).

## Garantias mínimas

- Um estorno por pagamento: a `Idempotency-Key` é o id do pagamento, e pedir de novo devolve o mesmo estorno.
- O pagamento só vira `refunded` com a confirmação do PSP.

## Garantias de sucesso

- O PSP aceita o estorno do valor inteiro; a confirmação chega depois, e o pagamento vira `refunded`.

## Cenário principal de sucesso

1. A conciliação vê o pagamento `refund_requested` e a cobrança `succeeded`.
2. O sistema pede ao PSP o estorno do valor inteiro da cobrança, com o id do pagamento como `Idempotency-Key`.
3. O PSP aceita o estorno para processamento, e a cobrança fica `refunded` com o estorno `processing`.
4. O PSP conclui o estorno e manda o webhook `refund.succeeded` (UC-PAY-02).
5. O sistema marca o pagamento como `refunded`.

## Extensões

- 2a. Circuit breaker aberto: nada é enviado, e a conciliação tenta de novo quando o breaker deixar.
- 3a. O PSP não responde: o estorno pode existir ou não. A conciliação pergunta de novo mais tarde e, se a cobrança ainda estiver `succeeded`, pede com a mesma chave.
- 3b. O PSP recusa o estorno (`409`, a cobrança não pode ser estornada): nada muda, e o log avisa uma pessoa.
- 4a. O webhook se perde: a conciliação encontra o estorno concluído e o aplica pelo mesmo caminho (UC-PAY-03).

## Variações de tecnologia

- Só existe estorno total, como o PayFake aceita. Estorno parcial pediria um valor e uma chave por estorno, e uma tabela de estornos.
- A chamada acontece fora de transação, como a cobrança: nenhuma linha fica travada enquanto o PSP responde.

## No código

- Port `ForRefundingPayments`, caso de uso `RefundPayment`, pacote `Commerce\Payments`. O PSP é o método `refund` do port `ForChargingCards`, e quem decide a hora de estornar é a conciliação (`ReconcilePayments`).
