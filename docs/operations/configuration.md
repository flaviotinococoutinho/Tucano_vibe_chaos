# Configuração

Tudo o que muda de um ambiente para outro vem de variáveis de ambiente, como pede o [fator III do Twelve-Factor App](https://12factor.net/pt_br/config): endereço de banco, segredo de webhook, timeout, pausa de worker, janela de conciliação. O código não tem número mágico que alguém queira ajustar num experimento; tem regra de negócio, e isso é outra coisa (veja a última regra abaixo).

Esta página é a referência. O `make config-check` (e o CI) confere que ela, o código e o `compose.yaml` contam a mesma história.

## As regras

1. **Um lugar só para ler o ambiente.** Cada serviço lê as variáveis num ponto: `config/*.php` no Laravel e no Lumen, `Config::fromEnvironment` no tracking e `loadConfig` nos serviços Node. O resto do código recebe os valores prontos, pelo container ou pelo construtor. No Laravel isso é obrigatório: depois do `config:cache`, um `env()` fora de `config/` devolve `null`.
2. **Todo valor tem um padrão que serve para a stack.** O serviço sobe sem nenhuma variável definida. O compose define as que mudam de verdade (endereços e segredos) e as que o laboratório deixa à mostra.
3. **Serviço de apoio usa o nome que a ferramenta já usa.** `APP_*`, `LOG_*`, `DB_*`, `REDIS_*`, `CACHE_*`, `QUEUE_*`, `MAIL_*`, `AWS_*`, `MONGO_*`, `KAFKA_*` e `FLAGD_*` são os nomes que quem conhece Laravel, Node ou as SDKs espera encontrar. Os parceiros entram do mesmo jeito, com o nome deles: `PAYFAKE_*` e `CARRIERS_*`.
4. **O resto segue `<ÁREA>_<RECURSO>_<AJUSTE>_<UNIDADE>`**, com a área no plural: `PAYMENTS_RECONCILIATION_QUIET_SECONDS`, `JOURNEYS_STALLED_ALERT_LIMIT`, `OUTBOX_RELAY_BATCH_SIZE`.
5. **Tempo diz a unidade no nome**, sempre: `_MS`, `_SECONDS`, `_MINUTES`, `_HOURS` ou `_DAYS`. Contagem diz o que conta: `_ATTEMPTS`, `_TRIES`, `_RETRIES`, `_LIMIT`, `_BATCH_SIZE`, `_THRESHOLD`, `_PERCENT`, `_MAX_ENTRIES`. Um `TIMEOUT=2` não diz se são 2 segundos ou 2 milissegundos, e essa dúvida já derrubou muito sistema.
6. **Segredo segue o mesmo caminho.** No laboratório, `APP_KEY`, senhas e segredos de webhook ficam no compose porque são de mentira. Num ambiente de verdade eles viriam de um cofre (Secrets Manager, Vault) para o ambiente do processo, e nunca para o repositório.
7. **Regra de negócio não é configuração.** O limite de 10 unidades por item, as 3 visitas de entrega, o tamanho dos campos e o layout do Snowflake ficam no código, com teste. Mudar um deles é mudar o produto, e isso passa por PR, não por variável.

## Como mudar um valor

Para uma rodada de experimento, crio um `compose.override.yaml` na raiz (o Git ignora) e recrio só o serviço:

```yaml
services:
  logistics-stalled-journeys-watch:
    environment:
      JOURNEYS_STALLED_AFTER_SECONDS: "120"
```

```bash
docker compose up -d --no-deps logistics-stalled-journeys-watch
```

O Compose junta o override com o `compose.yaml` sozinho. O `--no-deps` evita recriar as dependências, que já estão de pé. O `APP_ENV` da stack inteira vem do `.env` da raiz (ou de `APP_ENV=staging make up`), porque o compose usa `${APP_ENV:-local}`.

## Comum a todos os serviços

| Variável | Padrão | Quem lê | O que faz |
|---|---|---|---|
| `APP_NAME` | o nome do serviço | todos | campo `service` dos logs e nome do cliente de flags |
| `APP_ENV` | `production` (a stack passa `local`) | todos | `local`, `staging` ou `production`; em produção o `ProductionGuard` desliga as flags `chaos.*` e `labs.*`, e um valor desconhecido conta como produção |
| `APP_DEBUG` | `false` | PHP | detalhe do erro na resposta; fica `false` até no laboratório, para os problem details saírem como em produção |
| `APP_KEY` | nenhum | commerce e logistics | chave de criptografia do Laravel |
| `APP_URL` | `http://localhost:8082` no commerce, `8083` na logistics | commerce e logistics | URL base, para links gerados pelo framework |
| `LOG_CHANNEL` | `stderr` (`null` nos testes) | PHP | para onde vão os logs: uma linha JSON por evento no stderr |
| `LOG_LEVEL` | `info` | todos | nível PSR-3 mínimo dos logs |
| `HOST` | `0.0.0.0` | tracking, partners-sim e bff | interface onde o servidor escuta |
| `PORT` | `9501` no tracking, `4000` no partners-sim, `3000` no bff | tracking, partners-sim e bff | porta HTTP ([fator VII](https://12factor.net/pt_br/port-binding)); os apps PHP-FPM não escutam porta, quem escuta é o nginx |

## Feature flags

| Variável | Padrão | Quem lê | O que faz |
|---|---|---|---|
| `FLAGS_DRIVER` | `flagd` (`memory` nos testes) | PHP | de onde vêm as flags: o flagd, ou a memória, para rodar sem ele |
| `FLAGD_HOST` e `FLAGD_PORT` | `toxiproxy` e `18013` | PHP | o flagd, pelo Toxiproxy |
| `FLAGS_CACHE_SECONDS` | `2` | PHP | quanto tempo uma avaliação fica guardada; uma flag lida o tempo todo vira uma chamada a cada poucos segundos |
| `FLAGD_TIMEOUT_MS` | `300` | PHP | uma flag nunca atrasa um request: passou disso, vale o fallback da chamada |
| `FLAGD_CONNECT_TIMEOUT_MS` | `200` | PHP | a parte do timeout gasta para conectar |

"PHP" aqui quer dizer catalog, commerce, logistics e tracking.

## Bancos, cache e ids

| Variável | Padrão | Quem lê | O que faz |
|---|---|---|---|
| `DB_HOST` | `toxiproxy` | catalog, commerce e logistics | o banco, pelo Toxiproxy |
| `DB_PORT` | `13306` no catalog, `15432` no commerce, `15433` na logistics | catalog, commerce e logistics | a porta do proxy de cada banco |
| `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` | o nome do serviço | catalog, commerce e logistics | cada serviço tem o seu banco e o seu role |
| `DB_SSLMODE` | `prefer` | commerce e logistics | TLS com o PostgreSQL; num ambiente de verdade, `verify-full` |
| `DB_CONNECT_TIMEOUT_SECONDS` | `2` | catalog, commerce e logistics | o handshake inteiro com o banco; o padrão do driver é 30 s, tanto quanto o nginx espera o request |
| `DB_READ_TIMEOUT_SECONDS` | `2` | catalog | quanto o mysqlnd espera uma resposta do MySQL (o padrão dele é um dia) |
| `DB_SERIALIZABLE_ATTEMPTS` | `5` | commerce | uma transação serializable recusada com `40001` roda de novo desde o começo, até esse total de vezes |
| `REDIS_HOST` e `REDIS_PORT` | `toxiproxy` e `16379` | PHP | o Redis, pelo Toxiproxy |
| `REDIS_PASSWORD` | nenhuma | catalog, commerce e logistics | senha do Redis; o do laboratório não tem |
| `REDIS_DB` e `REDIS_CACHE_DB` | `0` e `1` | catalog, commerce e logistics | o banco das chaves do serviço e o do cache, separados para um flush do cache não levar o resto |
| `REDIS_PREFIX` e `CACHE_PREFIX` | `<serviço>-database-` e `<serviço>-cache-` | catalog, commerce e logistics | todos dividem o mesmo Redis, então as chaves começam pelo nome do serviço |
| `REDIS_TIMEOUT_MS` e `REDIS_READ_TIMEOUT_MS` | `1000` | PHP (o tracking só o primeiro) | o phpredis espera para sempre por padrão, e um Redis mudo seguraria o request |
| `REDIS_MAX_RETRIES` | `3` | commerce e logistics | quantas vezes o phpredis reconecta sozinho |
| `REDIS_BACKOFF_BASE_MS` e `REDIS_BACKOFF_CAP_MS` | `100` e `1000` | commerce e logistics | a espera entre essas reconexões, com jitter |
| `CACHE_STORE` | `redis` (`array` nos testes) | catalog, commerce e logistics | onde fica o cache do framework |
| `MONGO_URI` e `MONGO_DATABASE` | `mongodb://toxiproxy:17017/?directConnection=true` e `<serviço>_read` | commerce e logistics | os read models no MongoDB |
| `MONGO_CONNECT_TIMEOUT_MS`, `MONGO_SERVER_SELECTION_TIMEOUT_MS` e `MONGO_SOCKET_TIMEOUT_MS` | `2000`, `2000` e `5000` | commerce e logistics | o padrão do driver é 10 s, 30 s e 5 min |
| `SNOWFLAKE_DATACENTER_ID` e `SNOWFLAKE_WORKER_ID` | `1` e `1` no commerce, `11` na logistics | commerce e logistics | o nó dos ids Snowflake; cada processo que gera id precisa do seu (a stack passa `12` para o `logistics-order-intake`) |

## Kafka, outbox e consumers

| Variável | Padrão | Quem lê | O que faz |
|---|---|---|---|
| `KAFKA_BROKERS` | `toxiproxy:19092` | catalog, commerce e logistics | o Kafka, pelo Toxiproxy, para o caos alcançar o tráfego |
| `KAFKA_PRODUCER_LINGER_MS` | `5` | catalog, commerce e logistics | quanto o producer espera para juntar mensagens num lote |
| `KAFKA_PRODUCER_MESSAGE_TIMEOUT_MS` | `10000`; `5000` no catalog | catalog, commerce e logistics | mensagem sem ack até lá falha: o relay desfaz e tenta de novo; no catalog, é quanto uma escrita espera pelo Kafka depois do commit no MySQL |
| `KAFKA_CONSUMER_POLL_TIMEOUT_MS` | `1000` | commerce e logistics | quanto um poll espera por um registro, e também quanto um `SIGTERM` pode demorar a ser notado |
| `KAFKA_CONSUMER_RETRY_MAX_ATTEMPTS` | `5` | commerce e logistics | tentativas de um handler que falhou por algo que pode passar, antes da DLQ |
| `KAFKA_CONSUMER_RETRY_BASE_DELAY_MS` e `KAFKA_CONSUMER_RETRY_MAX_DELAY_MS` | `200` e `5000` | commerce e logistics | backoff exponencial com full jitter entre as tentativas |
| `ORDER_INTAKE_RETRY_MAX_ATTEMPTS`, `ORDER_INTAKE_RETRY_BASE_DELAY_MS` e `ORDER_INTAKE_RETRY_MAX_DELAY_MS` | `8`, `500` e `10000` | logistics | a entrada de pedidos espera mais: um pedido pago pode chegar antes da cópia do catálogo ter o produto |
| `OUTBOX_RELAY_BATCH_SIZE` | `100` | commerce e logistics | eventos publicados por transação do relay |
| `OUTBOX_RELAY_IDLE_PAUSE_MS` e `OUTBOX_RELAY_FAILURE_PAUSE_MS` | `200` e `2000` | commerce e logistics | a pausa do relay depois de uma rodada vazia e depois de uma que falhou |

## commerce

| Variável | Padrão | O que faz |
|---|---|---|
| `ORDERS_RESERVATION_MINUTES` | `15` | quanto tempo um pedido novo segura o estoque enquanto espera o pagamento |
| `ORDERS_EXPIRY_IDLE_PAUSE_MS` e `ORDERS_EXPIRY_FAILURE_PAUSE_MS` | `5000` e `2000` | a pausa do `commerce-order-expiry` quando nenhum pedido venceu, e depois de uma rodada que falhou |
| `INVENTORY_OPTIMISTIC_HOLD_ATTEMPTS` | `5` | a estratégia otimista lê de novo quando alguém mudou a linha no meio; depois disso, desiste |
| `IDEMPOTENCY_KEYS_TTL_HOURS` | `24` | quanto tempo um `Idempotency-Key` é lembrado; a mesma chave depois disso é um request novo |
| `PAYFAKE_URL` | `http://toxiproxy:14001` | o PSP, pelo Toxiproxy |
| `PAYFAKE_TIMEOUT_MS` e `PAYFAKE_CONNECT_TIMEOUT_MS` | `2000` e `500` | a chamada inteira ao PSP e a parte dela gasta para conectar |
| `PAYFAKE_WEBHOOK_SECRET` | `whsec_local_payfake` | o segredo do HMAC dos webhooks, o mesmo do partners-sim |
| `PAYFAKE_WEBHOOK_TOLERANCE_SECONDS` | `300` | webhook assinado mais velho (ou mais novo) que isso é recusado como replay |
| `PAYFAKE_CIRCUIT_FAILURE_THRESHOLD` e `PAYFAKE_CIRCUIT_WINDOW_SECONDS` | `5` e `30` | tantas falhas dentro da janela abrem o circuito |
| `PAYFAKE_CIRCUIT_OPEN_SECONDS` | `20` | quanto tempo o circuito fica aberto antes da primeira chamada de teste |
| `PAYFAKE_CIRCUIT_TRIAL_SECONDS` | `10` | quanto a chamada de teste segura o lock do meio aberto, no máximo |
| `PAYMENTS_RECONCILIATION_QUIET_SECONDS` | `60` | um pagamento sem a palavra final do PSP é consultado depois desse silêncio ([UC-PAY-03](../use-cases/UC-PAY-03-reconcile-payments.md)) |
| `PAYMENTS_RECONCILIATION_LOST_CHARGE_AFTER_SECONDS` | `3600` | uma cobrança que o PSP recebeu e não mostra mais ganha esse tempo para aparecer; depois, o pagamento falha com `charge_lost` |
| `PAYMENTS_RECONCILIATION_IDLE_PAUSE_MS` e `PAYMENTS_RECONCILIATION_FAILURE_PAUSE_MS` | `5000` e `2000` | a pausa do `commerce-payment-reconciler` quando nada venceu, e depois de uma rodada que falhou |

## logistics

| Variável | Padrão | O que faz |
|---|---|---|
| `CARRIERS_URL` | `http://toxiproxy:14002` | a CarrierFake, pelo Toxiproxy |
| `CARRIERS_TIMEOUT_MS` e `CARRIERS_CONNECT_TIMEOUT_MS` | `2000` e `500` | a chamada inteira à transportadora e a parte dela gasta para conectar |
| `CARRIERS_WEBHOOK_SECRET` | `whsec_local_carriers` | o segredo do HMAC dos webhooks, o mesmo do partners-sim |
| `CARRIERS_WEBHOOK_TOLERANCE_SECONDS` | `300` | webhook assinado mais velho (ou mais novo) que isso é recusado como replay |
| `JOURNEYS_RECONCILIATION_QUIET_SECONDS` | `60` | uma remessa com a transportadora e sem notícia por esse tempo é comparada com o histórico dela ([UC-SHP-12](../use-cases/UC-SHP-12-reconcile-journeys.md)) |
| `JOURNEYS_RECONCILIATION_IDLE_PAUSE_MS` e `JOURNEYS_RECONCILIATION_FAILURE_PAUSE_MS` | `5000` e `2000` | a pausa do `logistics-journey-reconciler` quando nada venceu, e depois de uma rodada que falhou |
| `JOURNEYS_STALLED_AFTER_SECONDS` | `3600` | uma remessa com a transportadora e sem passo novo por esse tempo está parada ([UC-SHP-13](../use-cases/UC-SHP-13-watch-stalled-journeys.md)) |
| `JOURNEYS_STALLED_WATCH_EVERY_SECONDS` | `900` | de quanto em quanto tempo a vigia faz a leitura analítica |
| `JOURNEYS_STALLED_ALERT_LIMIT` | `50` | quantas remessas um alerta lista, no máximo; o total vai no assunto |
| `JOURNEYS_STALLED_ALERT_REPEAT_SECONDS` | `14400` | as mesmas remessas não são alertadas de novo antes disso; uma remessa nova parada sai na hora |
| `JOURNEYS_STALLED_QUERY_TIMEOUT_MS` | `5000` | o `statement_timeout` da leitura analítica, para ela nunca segurar o banco dos webhooks |
| `JOURNEYS_STALLED_WATCH_FAILURE_PAUSE_MS` | `10000` | a pausa da vigia depois de uma rodada que falhou |
| `ALERTS_EMAIL_TO` | `ops@tucano.local` | quem recebe os alertas da operação por e-mail |
| `MAIL_MAILER` | `smtp` (`array` nos testes) | por onde sai o e-mail |
| `MAIL_HOST` e `MAIL_PORT` | `toxiproxy` e `11025` | o Mailpit, pelo Toxiproxy |
| `MAIL_USERNAME` e `MAIL_PASSWORD` | nenhum | credenciais do SMTP; o Mailpit aceita qualquer uma |
| `MAIL_TIMEOUT_SECONDS` | `5` | um servidor de e-mail mudo não segura a rodada da vigia |
| `MAIL_FROM_ADDRESS` e `MAIL_FROM_NAME` | `logistics@tucano.local` e `Tucano logistics` | o remetente dos alertas |
| `AWS_ENDPOINT` | `http://toxiproxy:14566` | o Floci, a AWS local, pelo Toxiproxy |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` e `AWS_DEFAULT_REGION` | `test`, `test` e `us-east-1` | credenciais do Floci, que aceita qualquer valor |
| `LABELS_BUCKET` | `tucano-labels` | o bucket das etiquetas |
| `LABELS_QUEUE` | `label-jobs` | a fila SQS das etiquetas, que o `logistics-label-worker` lê |
| `LABELS_JOB_TRIES` | `3` | tentativas de um job de etiqueta; combina com o `maxReceiveCount` da fila em `infra/floci/ready.d` |
| `LABELS_JOB_BACKOFF_SECONDS` | `5,20` | a espera antes de cada tentativa depois da primeira, separadas por vírgula |
| `LABELS_JOB_TIMEOUT_SECONDS` | `30` | o tempo de uma tentativa; fica abaixo da visibilidade da fila (60 s), para o SQS nunca entregar o job a um segundo worker no meio |
| `QUEUE_CONNECTION` | `sync` | a fila de um job sem conexão; a das etiquetas diz a sua (`sqs`) |
| `SQS_PREFIX` | `http://toxiproxy:14566/000000000000` | a URL da conta no SQS do Floci |
| `SQS_TIMEOUT_MS` e `SQS_CONNECT_TIMEOUT_MS` | `5000` e `2000` | o padrão do Laravel espera 60 s pelo SQS, e uma fila lenta assim é uma fila fora do ar |
| `S3_TIMEOUT_MS` e `S3_CONNECT_TIMEOUT_MS` | `5000` e `2000` | as chamadas ao S3 das etiquetas |
| `TRACKING_TABLE` | `tracking_lookup` | a tabela do DynamoDB com a página pública de rastreio |
| `DYNAMODB_TIMEOUT_MS` e `DYNAMODB_CONNECT_TIMEOUT_MS` | `3000` e `1000` | a página responde a uma pessoa esperando: um DynamoDB lento vira 503, não um request pendurado |
| `TRACKING_PAGE_RETENTION_DAYS` | `90` | quantos dias a página fica depois do último passo; o TTL do DynamoDB apaga depois |

## catalog

| Variável | Padrão | O que faz |
|---|---|---|
| `CATALOG_CACHE_TTL_SECONDS` | `300` | quanto um produto fica no cache |
| `CATALOG_CACHE_TTL_JITTER_PERCENT` | `10` | cada entrada vive o TTL com essa variação, para as escritas de uma mesma leva não vencerem juntas |
| `CATALOG_CACHE_MISSING_TTL_SECONDS` | `30` | quanto um "não existe" fica no cache; curto, para um produto ativado logo depois de um 404 não sumir por muito tempo |
| `CATALOG_CACHE_REBUILD_LOCK_SECONDS` | `5` | o lock de quem reconstrói a entrada; se ele morre no meio, o lock vence sozinho |
| `CATALOG_CACHE_REBUILD_WAIT_STEPS` e `CATALOG_CACHE_REBUILD_WAIT_STEP_MS` | `10` e `50` | quem encontra o lock ocupado olha de novo tantas vezes, com esse intervalo, antes de ir ao MySQL |
| `CATALOG_PAGE_SIZE` | `20` | produtos por página no `GET /v1/products`; a resposta diz o valor em `perPage` |
| `CATALOG_REPUBLISH_BATCH_SIZE` | `100` | o `catalog:republish` manda os snapshots em lotes desse tamanho |

## tracking

| Variável | Padrão | O que faz |
|---|---|---|
| `SWOOLE_WORKERS` | `2` | processos worker do Swoole |
| `SWOOLE_MAX_WAIT_SECONDS` | `5` | no `SIGTERM`, os requests em andamento têm esse tempo para terminar, dentro dos 10 s que o Docker espera |

As outras variáveis do tracking (`HOST`, `PORT`, `REDIS_*`, `FLAGD_*`) estão nas tabelas comuns. Um valor inválido derruba o boot com uma mensagem que diz qual variável e por quê.

## partners-sim

| Variável | Padrão | O que faz |
|---|---|---|
| `PAYFAKE_WEBHOOK_URL` | `http://kong:8000/api/commerce/v1/webhooks/payfake` | para onde o PayFake manda os webhooks |
| `PAYFAKE_PROCESSING_MIN_MS` e `PAYFAKE_PROCESSING_MAX_MS` | `300` e `1500` | quanto tempo uma cobrança ou um estorno fica processando antes de liquidar |
| `PAYFAKE_RETENTION_HOURS` e `PAYFAKE_RETENTION_MAX_ENTRIES` | `24` e `20000` | quanto tempo e quantas cobranças (e Idempotency-Keys) o PayFake guarda na memória |
| `PAYFAKE_TIMEOUT_HOLD_MS` | `30000` | o máximo que a chance de timeout segura uma resposta, para um cliente que nunca desiste |
| `CARRIERS_WEBHOOK_URL` | `http://kong:8000/api/logistics/v1/webhooks/carriers` | para onde a CarrierFake manda os webhooks |
| `CARRIERS_STEP_MIN_MS` e `CARRIERS_STEP_MAX_MS` | `1000` e `4000` | o tempo entre um passo da jornada e o seguinte |
| `CARRIERS_RETENTION_HOURS` e `CARRIERS_RETENTION_MAX_ENTRIES` | `24` e `20000` | quanto tempo e quantas coletas, com o histórico, a CarrierFake guarda; com pouco tempo, dá para ver a conciliação encontrar um histórico vazio |
| `WEBHOOKS_RETRY_DELAYS_MS` | `1000,2000,4000,8000,16000` | a espera antes de cada nova tentativa de um webhook; depois da última, ele é abandonado |
| `WEBHOOKS_ATTEMPT_TIMEOUT_MS` | `5000` | quanto uma tentativa espera a resposta de quem recebe |

Os segredos `PAYFAKE_WEBHOOK_SECRET` e `CARRIERS_WEBHOOK_SECRET` são os mesmos do commerce e da logistics.

## bff

| Variável | Padrão | O que faz |
|---|---|---|
| `CATALOG_URL`, `COMMERCE_URL` e `LOGISTICS_URL` | `http://toxiproxy:18081`, `http://toxiproxy:18082` e `http://toxiproxy:18083` | onde o BFF encontra cada serviço, cada um pelo seu proxy no Toxiproxy (`bff-catalog`, `bff-commerce` e `bff-logistics`), para um laboratório cortar uma tela de cada vez |
| `UPSTREAM_TIMEOUT_MS` | `5000` | a troca inteira com um serviço, corpo incluído; passou disso, a tela responde 503 com `Retry-After`. Fica acima dos 2 s que o commerce dá ao PSP, para o BFF não desistir de um pagamento que o commerce ainda espera |

Um endereço que não é URL http ou https, ou um timeout fora de 1 a 60000, impede a subida, com a lista do que está errado.

## Só nos testes

Estas não configuram nenhum serviço: dizem aos testes de integração onde está a infraestrutura de verdade. Sem elas, os testes que precisam dela são pulados.

| Variável | Quem usa | O que faz |
|---|---|---|
| `FLOCI_ENDPOINT` | testes da logistics | liga os testes contra o S3 e o DynamoDB do Floci |
| `MESSAGING_PG_DSN`, `MESSAGING_PG_USER` e `MESSAGING_PG_PASSWORD` | testes do pacote `tucano/messaging` | o PostgreSQL da outbox e da inbox |
| `MESSAGING_KAFKA_BROKERS` | testes do pacote `tucano/messaging` | um broker que cria tópicos sozinho, para o round trip |
| `READ_MODELS_MONGO_URI` | testes do pacote `tucano/read-models` | o MongoDB das migrações dos read models |
