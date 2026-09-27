# tucano/messaging

A mecânica de mensageria que commerce, logistics e catalog compartilham. É infraestrutura, não domínio: os serviços usam essas peças nos seus adapters.

| Peça | O que faz |
|---|---|
| `RdKafkaProducer` | producer idempotente (`enable.idempotence`, `acks=all`, `lz4`), que só considera entregue depois do `flush()` |
| `RdKafkaConsumer` | consumer at-least-once: commit manual do offset depois do handler, retry com backoff e DLQ em `dlq.<grupo>`; um `SIGTERM` no meio das tentativas não confirma nada |
| `IncomingEvent` | o primeiro passo de todo handler: o registro vira `CloudEvent`, ou uma `PermanentFailure` com a posição no tópico quando não é um |
| `RetryPolicy` | backoff exponencial com full jitter; `PermanentFailure` vai direto para a DLQ, conexão perdida com o banco (`LostConnection`) tenta sem limite, e o resto tem tentativas contadas |
| `OutboxWriter` | grava o CloudEvent em `outbox_messages` usando a conexão (e a transação) de quem chama |
| `OutboxRelay` e `OutboxRelayWorker` | polling publisher com `FOR UPDATE SKIP LOCKED`; marca como publicado só depois do ack do Kafka, e abre uma conexão nova depois de um lote que falhou |
| `Inbox` | deduplicação por consumidor com `ON CONFLICT DO NOTHING`, na mesma transação do efeito |
| `StopSignal` | parada graciosa no `SIGTERM`: o worker termina a mensagem atual antes de sair |
| `InMemoryProducer` | dublê para testes, que também simula o Kafka fora do ar |

O pacote usa PDO puro, e não o Eloquent ou o query builder, para funcionar igual no Laravel e no Lumen. No Laravel, o `OutboxWriter` recebe `DB::connection()->getPdo()`: é a mesma conexão, então a escrita na outbox participa da transação do caso de uso. O `OutboxRelay` recebe uma fábrica (`Closure(): PDO`), porque ele vive mais que qualquer conexão: se o banco reiniciar, o PDO antigo morre, e o relay pede outro.

## Garantias

- **Produção**: estado e evento na mesma transação; o relay publica depois. Se cair no meio, publica de novo (at-least-once).
- **Consumo**: offset confirmado só depois do processamento. Consumidor que cai reprocessa; por isso todo handler usa a `Inbox`.
- **Mensagem venenosa**: não trava a partição; depois das tentativas (ou na hora, se for `PermanentFailure`) ela vai para `dlq.<grupo>` com o erro e a origem nos headers.
- **Banco fora do ar**: aí a partição espera. A mensagem não tem culpa, e desistir dela mandaria para a DLQ uma mensagem boa, seguida de todas as outras que falhariam do mesmo jeito. O log registra cada tentativa em warning, e o consumo segue quando o banco volta.

## Testes

Os unitários rodam em qualquer lugar. Os de integração usam PostgreSQL e Kafka reais e pulam quando as variáveis não existem:

```bash
make packages-check
docker run --rm --network chaos-playground_backend -v "$PWD":/app -w /app/packages/php/messaging \
  -e MESSAGING_PG_DSN='pgsql:host=postgres;dbname=commerce_test' -e MESSAGING_PG_USER=commerce -e MESSAGING_PG_PASSWORD=commerce \
  chaos-playground/php-base:8.4 vendor/bin/phpunit --group integration
```

O teste de round trip no Kafka precisa de um broker que crie tópicos sozinho (`MESSAGING_KAFKA_BROKERS`), como o do CI. O broker da stack não cria tópicos por acidente, de propósito.
