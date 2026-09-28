# Laboratório: banco fora do ar

O PostgreSQL cai por alguns segundos, como num restart ou num failover. O que acontece com os eventos?

Dois processos de longa duração dependem do banco o tempo todo: o relay da outbox, que publica os eventos, e os consumidores Kafka, que aplicam os eventos de outros contextos. A API sofre menos, porque cada request do PHP-FPM abre a conexão de novo. Um worker vive horas com a mesma conexão, e é aí que uma queda curta vira um problema longo.

## Como derrubar o banco

O Toxiproxy fica entre os serviços e o PostgreSQL. Desligar o proxy fecha as conexões abertas e recusa as novas:

```bash
curl -s -X POST localhost:8474/proxies/commerce-postgres -d '{"enabled": false}'
# ... o tempo da queda ...
curl -s -X POST localhost:8474/proxies/commerce-postgres -d '{"enabled": true}'
```

Para a logística, o proxy é o `logistics-postgres`.

## O relay que não voltava

Três segundos sem banco, e depois um pedido novo.

| | Antes da correção | Depois |
|---|---|---|
| durante a queda | `outbox relay failed, will retry` a cada 2 s | o mesmo |
| depois da queda | o mesmo aviso para sempre: 14 vezes em 20 s, e o `order.placed` nunca saiu | `order.placed` publicado 2 s depois do pedido |

O relay recebia um PDO pronto e guardava aquele objeto para sempre. Quando o banco derrubou a conexão, o PDO morreu junto, e cada lote falhava no mesmo objeto morto. Os workers de expiração e de conciliação voltaram sozinhos, porque usam a conexão do Laravel, que reconecta quando perde o servidor.

A correção foi trocar o PDO por uma fábrica de conexão. Depois de qualquer lote que falhe, o relay descarta a conexão, e o lote seguinte abre outra. Numa queda de verdade, o sintoma antigo seria o pior possível: nenhum erro de negócio, nenhum alarme, só eventos acumulando na outbox.

## O consumidor que desistia cedo

Com o banco da logística fora por 45 s, eu paguei um pedido. O `order.paid` chegou ao consumidor `logistics.order-intake` no meio da queda.

| Hora | O que aconteceu |
|---|---|
| 13:55:22 | banco da logística fora |
| 13:55:27 | `order.paid` chega; primeira falha: `SQLSTATE[08006]` |
| 13:55:27 a 13:56:03 | 12 tentativas, cada uma em warning, com espera aleatória de até 10 s |
| 13:56:07 | banco de volta |
| 13:56:09 | remessa criada |

Antes, esse consumidor tentava 8 vezes (cerca de 35 s) e mandava a mensagem para a DLQ. A mensagem não tinha defeito nenhum: quem estava fora era o banco, e a próxima mensagem falharia do mesmo jeito. Agora o retry distingue três casos:

| Falha | Resposta |
|---|---|
| mensagem ilegível ou recusa do domínio (`PermanentFailure`) | DLQ na hora: repetir não conserta |
| conexão perdida com o banco (`LostConnection`) | tenta sem limite: a partição espera o banco voltar |
| qualquer outra | tentativas contadas; esgotadas, DLQ |

Um `SIGTERM` durante as tentativas interrompe a espera e não confirma o offset. A mensagem volta depois do restart, em vez de ir para a DLQ só porque o container estava parando.

A decisão e as alternativas que descartei estão no [ADR 0017](../adr/0017-wait-for-the-database-not-the-dlq.md).

## A API que respondia 500

Este laboratório olhava para os workers e deixava a API de lado, porque ela reconecta sozinha a cada request. Reconectar, ela reconecta. O que ela respondia durante a queda, ninguém tinha perguntado, até a queda virar um experimento com hipótese escrita: sem banco, fechar um pedido deve recusar na hora e dizer quando tentar de novo ([`commerce-database-out`](../../chaos/experiments/commerce-database-out.json)).

| Fechar um pedido com o banco do commerce fora | Antes da correção | Depois |
|---|---|---|
| a resposta do commerce | `500`, com o detalhe escondido | `503`, com `Retry-After: 5` |
| o que a loja respondia, pelo BFF | `500` em 0,19 s | `503` em 0,13 s, com "tente em 5 s" |

A queda do banco não é um defeito do pedido: o mesmo pedido passa quando o banco volta. O `ProblemDetails` dos três serviços PHP agora reconhece uma conexão recusada ou perdida, com a lista de mensagens que o próprio Laravel mantém para cada driver, e responde `503` com `Retry-After`, num texto fixo, porque a mensagem do driver traz o host e a porta do banco. Qualquer outro erro de banco, como uma chave duplicada, continua um `500` com o detalhe escondido ([ADR 0026](../adr/0026-a-database-outage-is-unavailability.md)).

O outro lado da mesma queda também virou experimento. Com o banco da logística fora, a página de rastreio continua respondendo, em 0,03 s, porque ela lê a cópia no DynamoDB e nem sabe que o PostgreSQL existe ([`tracking-without-its-database`](../../chaos/experiments/tracking-without-its-database.json)).

## O que eu levo para a entrevista

- Conexão de longa duração é estado. Um worker precisa saber refazê-la; a API do PHP-FPM ganha isso de graça, por ser shared-nothing.
- Retry com limite não é resposta para tudo. Se a causa é a dependência, e não a mensagem, desistir só move o problema para a DLQ e quebra a ordem das mensagens.
- Parar a partição tem custo: as outras mensagens dela esperam. Por isso a espera sem limite vale só para falhas que param todas as mensagens igual, e o log registra cada tentativa.
- Parada graciosa também vale no meio de um retry: o que não terminou não é confirmado.
- A parte que "sofre menos" também precisa de hipótese. A API reconectava sozinha, e por isso ninguém tinha perguntado o que ela respondia enquanto não conseguia.
