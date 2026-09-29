# Ambiente local

Tudo roda em Docker Compose, num arquivo só (`compose.yaml`). Uso Colima no Mac, e a stack foi dimensionada para uma VM de 4 CPUs e 4 GB.

## Requisitos

- Docker com Compose v2. No Colima: `colima start --cpu 4 --memory 4 --disk 40`.
- Pelo menos 10 GB livres no disco do Mac (20 GB é o confortável). O disco da VM do Colima é um arquivo que cresce dentro do disco do Mac, e um Mac sem espaço vira erro de I/O dentro da VM.
- `make doctor` confere o disco do Mac, a memória, a CPU e o disco livre da VM antes de subir.

## Numa máquina nova

O `make setup` prepara tudo com Ansible: confere disco e memória, instala o Colima, o Docker CLI, o Compose e o jq (ou o Docker Engine, no Linux), liga a VM, escreve o `.env` e roda o `make up` até tudo ficar saudável. Rodar de novo não muda nada numa máquina pronta. Os detalhes, e como mudar o tamanho da VM ou as flags, estão no [README do Ansible](../../infra/ansible/README.md).

```bash
brew install ansible     # uma vez; no Linux, pipx install --include-deps ansible
make setup
make setup-check         # a qualquer momento: confere a máquina e a saúde da stack, sem mudar nada
```
- As imagens de infraestrutura ocupam cerca de 5 GB. Os serviços vão somar mais conforme entram.

## Subir e descer

| Comando | O que faz |
|---|---|
| `make base` | constrói as imagens base do PHP (8.3 e 8.4); só demora na primeira vez |
| `make up` | constrói o que faltar, sobe a stack e espera tudo ficar saudável |
| `APP_ENV=staging make up` | sobe usando as flags de staging (vale `production` também) |
| `make tools` | sobe junto o Kafka UI, o Adminer e o DynamoDB Admin |
| `make down` | para tudo e mantém os dados |
| `make clean` | apaga todos os volumes; pede confirmação |
| `make ps` e `make logs s=kafka` | estado dos containers e logs de um serviço |
| `make check s=commerce` | Pint, PHPStan, Deptrac e PHPUnit de um serviço PHP, dentro da rede da stack |
| `make kong-reload` | aplica o `infra/kong/kong.yml` no Kong em execução, sem downtime |
| `make trim` | devolve ao Mac o espaço liberado dentro da VM do Colima (depois de um `docker image prune`) |

Um boot a frio, com volumes vazios, leva uns 20 segundos até tudo ficar saudável.

## Configuração

Cada serviço lê a configuração de variáveis de ambiente, e os padrões já servem para a stack. A [referência de configuração](configuration.md) lista todas, com o padrão e o porquê de cada uma. Para mudar um valor num experimento, uso um `compose.override.yaml` (o Git ignora) e recrio só o serviço com `docker compose up -d --no-deps <serviço>`. O `make config-check` confere que compose, código e referência concordam.

## Endereços

Todas as portas escutam só em `127.0.0.1`.

| Serviço | Endereço | Acesso |
|---|---|---|
| Kong (proxy) | http://localhost:8000 | rotas `/bff`, `/api/catalog`, `/api/commerce`, `/api/logistics` e `/api/tracking` |
| Kong Admin API | http://localhost:8001 | - |
| nginx (apps PHP) | `localhost:8081` catalog, `8082` commerce, `8083` logistics; a `8182`, a borda do commerce, só na rede do compose | - |
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
| partners-sim (PayFake) | http://localhost:4000 | API de caos em `/_chaos/payfake` |
| flagd | `localhost:8013` (avaliação) e `localhost:8016` (OFREP) | - |
| Kafka UI | http://localhost:8080 | perfil `tools` |
| Adminer | http://localhost:8088 | perfil `tools` |
| DynamoDB Admin | http://localhost:8089 | perfil `tools` |

## Comandos do dia a dia

```bash
make topics                              # lista os tópicos
make consume t=commerce.orders.v2        # lê um tópico desde o início, com chave e headers
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
- **Memória**: a infraestrutura sozinha usa cerca de 1,2 GB; com os serviços, cerca de 1,3 GB. Com 4 GB na VM ainda sobra espaço para build.
- **Container perdeu um arquivo montado** (`No such file or directory` no healthcheck do Floci, por exemplo): o arquivo foi recriado no host depois que o container subiu, e o bind mount ficou apontando para o arquivo antigo. Acontece quando o git troca o working tree para um commit que não tem o arquivo (checkout de uma branch antiga) e volta. O `docker compose up -d --force-recreate <serviço>` monta de novo; os volumes com dados não são tocados.
- **Kafka reiniciando com `NodeExistsException` depois de recriar o container**: o broker antigo ainda está registrado no ZooKeeper até a sessão dele expirar (18 s). O broker novo sobe, encontra o registro e cai; o restart automático resolve sozinho em menos de um minuto.
- **Flag alterada pelo painel de caos voltou ao valor original**: é esperado. Todo `make up` recria a cópia de runtime a partir do arquivo versionado (veja [feature-flags.md](../architecture/feature-flags.md)).
