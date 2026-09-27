# 0017. Esperar o banco voltar em vez de mandar para a DLQ

- Status: aceito
- Data: 2026-09-27

## Contexto

Os consumidores Kafka são at-least-once: o offset só é confirmado depois do handler. Até aqui, qualquer falha ganhava algumas tentativas com backoff e, esgotadas, a mensagem ia para `dlq.<consumer-group>`. Dois experimentos mostraram o custo dessa regra única:

- Com o banco da logística fora por 45 s, um `order.paid` bom iria para a DLQ depois de cerca de 35 s. A próxima mensagem falharia do mesmo jeito, então a DLQ encheria de mensagens sem defeito nenhum.
- Mensagem na DLQ sai da ordem da partição. Se o pedido é cancelado enquanto o `order.paid` está lá, o replay chega depois do cancelamento e despacha um pedido estornado.

No mesmo experimento, o relay da outbox ficou preso a um PDO morto depois da queda: falhava para sempre, sem publicar nada.

## Decisão

- Cada tipo de falha tem a sua resposta no `RetryPolicy`:
  - `PermanentFailure` (mensagem ilegível, recusa do domínio): DLQ na hora.
  - Conexão perdida com o banco (`LostConnection`): tenta sem limite, com backoff e jitter. A partição espera o banco voltar.
  - Qualquer outra falha: tentativas contadas; esgotadas, DLQ.
- Um consumidor pode alargar o "sem limite" para a dependência dele. A ponte de etiquetas trata o SQS fora como o banco fora.
- Um `SIGTERM` no meio das tentativas interrompe a espera e não confirma o offset.
- O relay recebe uma fábrica de conexão e abre uma nova depois de um lote que falhou.
- Replay continua possível e precisa ser seguro: os handlers são idempotentes, e eventos do mesmo pedido que podem chegar trocados dão o mesmo resultado em qualquer ordem (a lápide `cancelled_orders`).

## Consequências

- Enquanto o banco estiver fora, uma partição para. As outras mensagens dela esperam, e o log registra cada tentativa em warning.
- A DLQ fica para o que é defeito da mensagem ou do código, que é o que uma pessoa precisa olhar.
- Um bug que quebra uma mensagem específica ainda vai para a DLQ depois das tentativas contadas, em vez de travar a partição para sempre.

## Alternativas consideradas

- **Tentativas contadas para tudo** (a regra anterior): simples, mas manda mensagens boas para a DLQ e quebra a ordem por chave.
- **Tentar sem limite para tudo**: preserva a ordem, mas um bug numa mensagem vira poison pill e trava a partição até alguém fazer deploy.
- **Tópicos de retry** (`orders.retry.1m`, `orders.retry.10m`): não bloqueiam a partição, mas também tiram a mensagem da ordem, que é justamente o que eu quero preservar.
