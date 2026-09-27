# Laboratório: conciliação de pagamentos

O PSP cobrou, mas o commerce não ficou sabendo. O que acontece com o pedido?

É no pagamento que o desfecho desconhecido aparece de verdade. A resposta da cobrança pode não voltar (timeout), o webhook pode não chegar (rede, deploy, bug do outro lado) e a própria cobrança pode se perder antes de chegar ao PSP. Nos três casos o pagamento fica `pending`, e só o PSP sabe o que aconteceu. O webhook empurra o desfecho; a conciliação é o lado que puxa: ela pergunta ao PSP e aplica a resposta pelo mesmo caminho do webhook.

## Como funciona

- O worker `commerce-payment-reconciler` pega, entre os pagamentos sem desfecho, o que está quieto há mais tempo, desde que esteja quieto há pelo menos 60 s. Pegar renova o `updated_at`, que funciona como lease: outro worker pula a linha (`SKIP LOCKED`), e o pagamento só volta depois de mais 60 s de silêncio.
- A pergunta usa a `reference` da cobrança, que é o id do pagamento: `GET /payfake/v1/charges?reference=<paymentId>`. O id da cobrança não serve, porque ele viaja justamente na resposta que pode ter se perdido.
- O que fazer com a resposta está na tabela de decisão do [UC-PAY-03](../use-cases/UC-PAY-03-reconcile-payments.md). O desfecho encontrado passa pelo mesmo caso de uso do webhook (UC-PAY-02), com um id estável na inbox (`reconciliation:<paymentId>:captured`), então não importa quem chega primeiro.
- A consulta e o estorno passam pelo mesmo circuit breaker da cobrança. Com o circuito aberto, o worker não pega pagamento nenhum e espera o tempo que o breaker indica.

## O estado `abandoned`

Quando o PSP não tem cobrança nenhuma, a conciliação espera enquanto o pedido espera: o cliente ainda pode repetir o pagamento com a mesma chave, e a repetição reenvia a cobrança. Quando o pedido deixa de esperar (a reserva venceu), a conciliação desiste, e o pagamento vira `abandoned`.

Desistir é um palpite. Uma repetição do cliente pode estar a caminho do PSP no mesmo instante, e a busca de um PSP de verdade pode estar atrasada em relação às cobranças. Em vez de tentar impedir a cobrança tardia, que chega de qualquer jeito, eu garanto que ela tenha para onde ir: `abandoned` aceita a palavra tardia do PSP. Se a cobrança aprovar depois, o dinheiro vai para estorno, porque o pedido já foi cancelado; se recusar, a recusa fica registrada. A conciliação também continua olhando os pagamentos `abandoned` que ganharam um id de cobrança, para o caso de o webhook dessa cobrança se perder.

A decisão e as alternativas estão no [ADR 0019](../adr/0019-abandoned-payments-accept-late-outcomes.md).

## O experimento

Três pedidos, com o PayFake descartando todo webhook:

```bash
curl -s -X PUT localhost:4000/_chaos/payfake -H 'Content-Type: application/json' \
  -d '{"webhooks":{"dropRate":1}}'
```

- **A**: pago normalmente. O webhook some.
- **B**: pago normalmente, e logo depois a reserva vence. Para não esperar 15 minutos, venci a reserva na mão (`UPDATE orders SET reservation_expires_at = now() WHERE id = '<B>'`), e o worker de expiração cancelou o pedido em poucos segundos.
- **C**: a cobrança se perde antes de chegar ao PayFake. Um toxic `timeout` no sentido de subida descarta o request, o commerce desiste depois de 2 s e o PayFake nunca vê nada:

```bash
curl -s -X POST localhost:8474/proxies/payfake/toxics \
  -d '{"name":"lost-request","type":"timeout","stream":"upstream","attributes":{"timeout":0}}'
# paga o pedido C: 202 depois de 2,04 s, pagamento pending e sem id de cobrança
curl -s -X DELETE localhost:8474/proxies/payfake/toxics/lost-request
```

O log do worker, com os pagamentos feitos às 13:17:48 (A e B) e às 13:18:07 (C):

| Hora | Pagamento | O PSP tinha | Resultado |
|---|---|---|---|
| 13:18:52 | A, `pending` | cobrança `succeeded` | `settled`: pagamento `captured`, pedido `paid` e `order.paid` publicado |
| 13:18:52 | B, `pending` | cobrança `succeeded` | `settled`: o pedido já estava cancelado, então o pagamento foi para `refund_requested` |
| 13:19:07 | C, `pending` | nada | `waiting`: o pedido ainda esperava |
| 13:19:53 | B, `refund_requested` | cobrança `succeeded` | `refund_sent`: estorno pedido com o id do pagamento como `Idempotency-Key` |
| 13:20:08 | C, `pending` | nada | `abandoned`: a reserva do pedido tinha vencido |
| 13:20:54 | B, `refund_requested` | cobrança `refunded`, estorno concluído | `settled`: pagamento `refunded` |

Cada passo esperou os 60 s de silêncio do pagamento, mais até 5 s do intervalo do worker. Com os webhooks funcionando, o mesmo desfecho chega em menos de 2 s: a conciliação é a rede de segurança, não o caminho principal.

No fim, repeti o pagamento do C com a mesma chave: `202` com o pagamento `abandoned`, e nada saiu para o PSP. Na outbox ficaram `order.placed` e `order.paid` do A, e `order.placed` e `order.cancelled` do B e do C. O B não tem `order.paid`: o dinheiro dele voltou.

## O que precisa de uma pessoa

Na primeira rodada, três pagamentos de experimentos antigos apareceram como `needs_attention`: tinham id de cobrança, mas o PayFake não tinha cobrança nenhuma. O PayFake guarda tudo em memória e tinha sido reiniciado. PSP de verdade não perde cobrança, então a conciliação não inventa desfecho: ela avisa em warning a cada rodada, até alguém decidir.

## O que eu levo para a entrevista

- Timeout não é falha, é desfecho desconhecido: o pagamento fica pendente, e alguém precisa perguntar depois.
- Webhook é at-least-once e pode nunca chegar. A conciliação é o lado pull, e os dois caminhos convergem no mesmo caso de uso idempotente.
- A consulta usa a referência do lojista, não o id do PSP, porque o id viaja na resposta que pode ter se perdido.
- Desistir de um pagamento não impede a cobrança tardia: o sistema precisa aceitar a palavra tardia do PSP e devolver o dinheiro.
- Em produção eu somaria a conciliação diária pelo relatório de liquidação do PSP, que pega até o que a consulta não enxerga.
