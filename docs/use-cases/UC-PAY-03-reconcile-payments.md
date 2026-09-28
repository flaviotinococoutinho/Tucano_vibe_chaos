# UC-PAY-03: Conciliar pagamentos pendentes

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Relógio (o worker `commerce-payment-reconciler`) |
| **Escopo** | Commerce (Payments) |
| **Gatilho** | um pagamento sem a palavra final do PSP passa 60 s sem novidade |

## Partes interessadas e interesses

- **Cliente**: ver o pedido pago mesmo quando o webhook se perde, e ter de volta o dinheiro que chegou tarde.
- **Tucano**: não ficar com dinheiro sem pedido, nem com pedido esperando um pagamento que nunca vai chegar.
- **PayFake**: receber consultas e estornos com chave de idempotência, sem nada em dobro.

## Pré-condições

- O pagamento está `pending` ou `refund_requested`, ou está `abandoned` e uma cobrança apareceu depois.

## Garantias mínimas

- Nenhuma linha fica travada enquanto o PSP responde: a consulta acontece fora de transação.
- Cada pagamento é conferido por um worker de cada vez, e o que um worker morto deixou pela metade volta para a fila quando fica quieto de novo.
- O desfecho encontrado é aplicado pelo mesmo caminho do webhook (UC-PAY-02): mesma transação, mesma inbox.
- Na dúvida, nada muda e o log avisa uma pessoa (warning com `needs_attention`).

## Garantias de sucesso

- O pagamento chega à palavra final do PSP (`captured`, `failed` ou `refunded`), ou vira `abandoned` quando o PSP nunca recebeu a cobrança e o pedido já não espera.

## Cenário principal de sucesso

1. O sistema pega o pagamento quieto há mais tempo e renova o `updated_at` dele.
2. O sistema pergunta ao PSP pela cobrança do pagamento, pela `reference`, que é o id do pagamento.
3. O PSP responde a cobrança como ela está agora.
4. O sistema aplica a linha da tabela de decisão que casa com o pagamento e a cobrança.
5. O sistema registra no log o que encontrou e o que fez, e volta ao passo 1.

## Tabela de decisão

| Pagamento | Cobrança no PSP | O que acontece |
|---|---|---|
| `pending` | nenhuma, e o pedido ainda espera | nada: o cliente ainda pode repetir o pagamento (UC-PAY-01, extensão 2b) |
| `pending` | nenhuma, e o pedido já não espera | o pagamento vira `abandoned` |
| `pending` com id de cobrança | nenhuma, dentro da janela da cobrança perdida | nada: o PSP ainda pode mostrar a cobrança (`charge_missing`) |
| `pending` com id de cobrança | nenhuma, com a janela fechada | o pagamento vira `failed` com `charge_lost`, pelo caminho da recusa (UC-PAY-02) |
| `pending` ou `abandoned` | `processing` | o id da cobrança fica anotado, e o resultado vem depois |
| `pending` ou `abandoned` | `succeeded` | a captura é aplicada (UC-PAY-02) |
| `pending` ou `abandoned` | `failed` | a recusa é aplicada (UC-PAY-02) |
| `refund_requested` | `succeeded` | o estorno é pedido (UC-PAY-04) |
| `refund_requested` | `refunded`, estorno em processamento | nada: o estorno termina sozinho |
| `refund_requested` | `refunded`, estorno concluído | o estorno é aplicado (UC-PAY-02) |
| qualquer outro par | | nada muda, e o log avisa uma pessoa |

## Extensões

- 1a. Circuit breaker aberto: o worker não pega pagamento nenhum e espera o tempo que o breaker indica.
- 1b. Nenhum pagamento quieto há 60 s: o worker espera 5 s.
- 1c. Outro worker está com o pagamento: o `SKIP LOCKED` pula a linha, e o `updated_at` renovado segura o pagamento longe das próximas rodadas.
- 2a. O PSP não responde (timeout ou 5xx): nada muda, e o pagamento volta na primeira rodada depois de ficar quieto de novo. As falhas contam no circuit breaker, como na cobrança.
- 3a. O pagamento tem id de cobrança, mas o PSP não tem cobrança nenhuma: a busca do PSP pode estar atrasada, então a cobrança ganha uma janela para aparecer (`PAYMENTS_RECONCILIATION_LOST_CHARGE_AFTER_SECONDS`, uma hora por padrão). Fechada a janela, o pagamento falha com `charge_lost`, e um pedido que ainda espera por ele é cancelado. No laboratório isso acontece quando o PayFake reinicia, porque ele guarda tudo em memória.
- 3b. Um estorno pedido, ou dinheiro que apareceu para um pagamento `abandoned`, e o PSP não tem a cobrança: ali pode haver dinheiro no PSP, então é caso para uma pessoa (`needs_attention`).
- 4a. Um webhook chega enquanto o PSP é consultado: quem chegar depois encontra o pagamento resolvido, e nada muda (`already_settled`).
- 4b. O cliente repete o pagamento no instante em que o sistema desiste: a cobrança criada depois chega por webhook ou por uma conciliação seguinte, e o dinheiro volta. Por isso `abandoned` aceita a palavra tardia do PSP.
- 4c. A captura de um pagamento `abandoned` não paga o pedido, que já não espera: o pagamento vai direto para `refund_requested`.
- \*a. O banco falha: o worker registra o erro, espera dois segundos e continua.

## Variações de tecnologia

- Desistir é um palpite, e o PSP pode discordar depois. Eu não tento impedir a cobrança tardia, que chega de qualquer jeito; eu garanto que ela tenha para onde ir.
- O `updated_at` faz papel de lease, sem coluna nova: pegar o pagamento é um `UPDATE ... WHERE id = (SELECT ... FOR UPDATE SKIP LOCKED) RETURNING` num comando só, e o índice parcial `payments_unsettled_idx` cobre só os pagamentos sem desfecho.
- O desfecho puxado pela conciliação entra na inbox com um id estável (`reconciliation:<paymentId>:captured`), então aplicar duas vezes não muda nada.
- A consulta usa a `reference` e não o id da cobrança, porque o id pode ter se perdido junto com a resposta da cobrança.
- Em produção eu somaria a conciliação diária pelo relatório de liquidação do PSP, que pega até o que a consulta não enxerga.

## No código

- Port `ForReconcilingPayments`, caso de uso `ReconcilePayments`, pacote `Commerce\Payments`. O laço é o comando `commerce:reconcile-payments`, e o tempo de silêncio vem de `payments.reconciliation.quiet_seconds`. O experimento está no [laboratório de conciliação](../labs/payment-reconciliation.md).
