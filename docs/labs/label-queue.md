# Laboratório: a fila de etiquetas

O bucket das etiquetas começa a falhar. O que acontece com as remessas?

A etiqueta é o primeiro trabalho da logística que passa por uma fila de trabalho, e não pelo log. O `ShipmentCreated` sai da outbox para o Kafka, a ponte `logistics-label-requests` põe um job na fila `label-jobs` do SQS, e o worker do Laravel gera a etiqueta, grava no S3 e move a remessa para `ready_for_pickup` ([UC-SHP-03](../use-cases/UC-SHP-03-generate-label.md)). Cada peça tem a sua forma de tentar de novo, e o experimento mostra as três.

## As regras da fila

| Peça | Quando tenta de novo | Quando desiste |
|---|---|---|
| job do Laravel | espera 5 s depois da primeira falha e 20 s depois da segunda | na terceira falha: o job vai para `failed_jobs` |
| fila SQS | a mensagem de um worker que morreu volta a ficar visível depois de 60 s | na terceira entrega sem resposta: vai para `label-jobs-dlq` |
| ponte no Kafka | com o SQS fora, a partição espera | nunca: o `ShipmentCreated` continua no tópico e pode ser relido |

## Como provocar

```bash
make flag key=chaos.logistics.label-failure-rate variant=most   # 80% das gravações falham
# pague alguns pedidos pelo Kong
make flag-reset key=chaos.logistics.label-failure-rate
```

Com 80% de falha e três tentativas, cada etiqueta sai com probabilidade 1 - 0,8³ = 48,8%.

## O que medi

**Primeira leva, 10 pedidos.** As tentativas seguiram as esperas: 10 primeiras às 14:27:25, 7 segundas às 14:27:31 e 4 terceiras às 14:27:52. Saíram 6 etiquetas. As 4 que falharam três vezes deveriam ter ido para `failed_jobs`, e sumiram. O log tinha uma linha só por job: `Database file at path [logistics] does not exist`.

O `config/queue.php` que vem com o Laravel guarda os jobs que falharam em `env('DB_CONNECTION', 'sqlite')`. O serviço usa PostgreSQL, mas o padrão da conexão tinha sido trocado só no `config/database.php`. Quando o job desiste, o Laravel apaga a mensagem da fila antes de gravar a falha. Com a gravação quebrada, o job some, e a remessa fica em `created` sem rastro nenhum. Corrigi o padrão no commerce e na logística, e um teste agora grava uma falha pelo mesmo caminho do worker.

**Segunda leva, 10 pedidos, já com a correção.** Saíram 4 etiquetas, uma na primeira tentativa, duas na segunda e uma na terceira; as 6 restantes foram para `failed_jobs` entre 14:32:39 e 14:32:42, uns 25 s depois das primeiras tentativas. O número bate com os 48,8% esperados.

**Terceira leva, 5 pedidos, para ver o log.** Erro de domínio não vai para o log de erros do serviço, porque no HTTP ele é resposta esperada; num job, esse silêncio escondia as tentativas. Agora cada tentativa que falha sai em warning, e a desistência sai em error:

```text
14:34:59 WARNING Label of shipment ...40bf failed on attempt 1 of 3: The label could not be stored: chaos, with a failure rate of 0.8.
14:35:05 WARNING Label of shipment ...40bf failed on attempt 2 of 3: ...
14:35:26 WARNING Label of shipment ...40bf failed on attempt 3 of 3: ...
14:35:26 ERROR   Label of shipment ...40bf gave up and waits in failed_jobs: ...
```

## Como recuperei

1. Com a flag de volta a zero, o `php artisan queue:retry all` devolveu os 10 jobs de `failed_jobs` à fila, e as 10 etiquetas saíram.
2. Sobraram as 4 remessas da primeira leva, cujos jobs sumiram. Parei a ponte, voltei o offset do grupo `logistics.label-requests` para o começo e subi de novo:

```bash
docker compose stop logistics-label-requests
docker compose exec kafka /opt/kafka/bin/kafka-consumer-groups.sh --bootstrap-server kafka:9092 \
  --group logistics.label-requests --topic logistics.shipments.v1 --reset-offsets --to-earliest --execute
docker compose up -d --no-deps logistics-label-requests
```

A ponte pediu de novo as 39 etiquetas: 35 terminaram em `not_needed`, porque a remessa já tinha etiqueta, e 4 em `attached`. As 39 remessas ficaram em `ready_for_pickup`.

## Um cuidado com as flags

Qualquer `docker compose up` que suba o flagd como dependência roda o `flags-init` de novo, e a flag volta ao valor do repositório. Numa das levas, recriei os workers no meio do experimento, e a taxa de falha voltou a zero sem aviso. Para recriar um worker durante o experimento, use `docker compose up -d --no-deps <serviço>`.

Por que o pedido de etiqueta parte do evento, e não de um dispatch depois do commit, está no [ADR 0018](../adr/0018-async-work-starts-from-the-event.md).

## O que eu levo para a entrevista

- Kafka é log e SQS é fila de trabalho. O log guarda o fato e deixa reler; a fila entrega cada mensagem a um worker e esquece depois de apagar.
- Job idempotente é o que torna seguro tentar de novo de qualquer lugar: retry do Laravel, redrive do SQS ou replay do Kafka. Aqui a chave do objeto é o código de rastreio, e a remessa avança uma vez só.
- O timeout do job precisa ficar abaixo da visibilidade da fila; senão, dois workers fazem o mesmo trabalho ao mesmo tempo.
- Todo caminho de desistência precisa deixar rastro. Um job que falha e não é gravado é pior que um job que trava, porque ninguém fica sabendo.
- O evento no log é a última rede de segurança: quando a fila e o `failed_jobs` perderam o trabalho, o replay do Kafka recuperou.
