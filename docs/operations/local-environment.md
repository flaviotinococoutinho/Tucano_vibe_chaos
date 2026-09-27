# Ambiente local

Tudo roda em Docker Compose, num arquivo só (`compose.yaml`). Uso Colima no Mac, e a stack foi dimensionada para uma VM de 4 CPUs e 4 GB.

## Requisitos

- Docker com Compose v2. No Colima: `colima start --cpu 4 --memory 4 --disk 40`.
- `make doctor` confere memória, CPU e disco livre da VM antes de subir.
- As imagens de infraestrutura ocupam cerca de 5 GB. Os serviços vão somar mais conforme entram.

## Subir e descer

| Comando | O que faz |
|---|---|
| `make up` | sobe a stack e espera tudo ficar saudável |
| `APP_ENV=staging make up` | sobe usando as flags de staging (vale `production` também) |
| `make tools` | sobe junto o Kafka UI, o Adminer e o DynamoDB Admin |
| `make down` | para tudo e mantém os dados |
| `make clean` | apaga todos os volumes; pede confirmação |
| `make ps` e `make logs s=kafka` | estado dos containers e logs de um serviço |

Um boot a frio, com volumes vazios, leva uns 20 segundos até tudo ficar saudável.

## Endereços

Todas as portas escutam só em `127.0.0.1`.

| Serviço | Endereço | Acesso |
|---|---|---|
| Kong (proxy) | http://localhost:8000 | - |
| Kong Admin API | http://localhost:8001 | - |
| Kong Manager | http://localhost:8002 | somente leitura, porque o Kong roda em DB-less |
| PostgreSQL | `localhost:5432` | `postgres`/`postgres`; `commerce`/`commerce`; `logistics`/`logistics` |
| MySQL | `localhost:3306` | `catalog`/`catalog`; root com senha `root` |
| MongoDB | `mongodb://localhost:27017/?directConnection=true` | sem autenticação |
| Redis | `localhost:6379` | - |
| Kafka (a partir do host) | `localhost:29092` | - |
| ZooKeeper | `localhost:2181` | - |
| Floci (AWS local) | http://localhost:4566 | `test`/`test`, região `us-east-1` |
| Mailpit | http://localhost:8025 | - |
| Toxiproxy API | http://localhost:8474 | - |
| flagd | `localhost:8013` (avaliação) e `localhost:8016` (OFREP) | - |
| Kafka UI | http://localhost:8080 | perfil `tools` |
| Adminer | http://localhost:8088 | perfil `tools` |
| DynamoDB Admin | http://localhost:8089 | perfil `tools` |

## Comandos do dia a dia

```bash
make topics                              # lista os tópicos
make consume t=commerce.orders.v1        # lê um tópico desde o início, com chave e headers
make psql db=logistics                   # psql com o role do serviço
make mysql                               # cliente MySQL no banco do catálogo
make mongo                               # mongosh
make redis-cli
make aws c="sqs list-queues"             # AWS CLI apontando para o Floci
make flags key=customer-42               # avalia todas as flags para um targeting key
make proxies                             # proxies do Toxiproxy e toxics ativos
```

Pelo Floci também dá para inspecionar sem consumir nada: http://localhost:4566/_aws/ses mostra os e-mails enviados, e `/_aws/sqs/messages?QueueUrl=...` espia uma fila.

## Problemas comuns

- **MongoDB a partir do host**: use `directConnection=true`. O replica set anuncia o endereço `mongo:27017`, que só resolve dentro da rede do compose; sem essa opção o driver tenta segui-lo e falha.
- **Kafka a partir do host**: use `localhost:29092`. Dentro da rede, as ferramentas usam `kafka:9092` e as aplicações usam `toxiproxy:19092` (explicação em [deployment.md](../architecture/deployment.md)).
- **Volume do Kafka com mais de 1 GB no `docker system df`**: são índices pré-alocados em arquivos esparsos. O uso real fica em poucos MB (`du -sh` dentro do volume mostra).
- **Memória**: a infraestrutura sozinha usa cerca de 1,2 GB. Com os serviços, 4 GB na VM é o mínimo confortável.
- **Flag alterada pelo painel de caos voltou ao valor original**: é esperado. Todo `make up` recria a cópia de runtime a partir do arquivo versionado (veja [feature-flags.md](../architecture/feature-flags.md)).
