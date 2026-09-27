# 0005. Floci como AWS local

- Status: aceito
- Data: 2026-09-27

## Contexto

A LocalStack passou a exigir conta e auth token na imagem principal a partir da versão 2026.03.0 (março de 2026), e o plano gratuito é só para uso não comercial. O projeto precisa de S3, SQS, SNS, DynamoDB e SES locais, sem conta e sem custo, e quem vem de times que usam LocalStack deve conseguir aproveitar o que já sabe.

## Decisão

Escolhi o [Floci](https://floci.io/floci/) 2.1 (licença MIT) no lugar da LocalStack, seguindo o guia oficial [Migrate from LocalStack](https://floci.io/floci/getting-started/migrate-from-localstack/):

- imagem `floci/floci:2.1.0-compat`, a variante que traz o AWS CLI e o boto3 já apontando para `http://localhost:4566`, sem `--endpoint-url`, para os init hooks;
- recursos (buckets, filas, tópicos, tabelas) criados por scripts em `/etc/floci/init/ready.d`, versionados em `infra/floci/`;
- variáveis nativas do Floci (`FLOCI_HOSTNAME`, `FLOCI_STORAGE_MODE=persistent`) e dados em `/app/data`;
- o SES repassa os e-mails para o Mailpit (`FLOCI_SERVICES_SES_SMTP_HOST`).

Os serviços usam os SDKs oficiais da AWS com o `endpoint` apontando para o Floci e as credenciais fictícias `test`/`test`: é o mesmo código que iria para produção.

## Consequências

- O Floci é binário nativo: sobe em milissegundos e ocupa algumas dezenas de MB, contra centenas da LocalStack.
- Porta 4566, credenciais, SDKs e caminhos `/etc/localstack/init/` continuam valendo, então migrar um projeto existente costuma ser só trocar a imagem.
- Os endpoints de inspeção ajudam no estudo: `/_aws/ses` mostra os e-mails enviados, e `/_aws/sqs/messages?QueueUrl=...` lê uma fila sem consumir as mensagens.
- URLs pré-assinadas do S3 precisam do host público (`localhost:4566`), não do nome interno do container. Esse detalhe fica encapsulado no adapter de storage.
- O Floci é um projeto jovem. Comportamentos finos da AWS, como a consistência eventual do DynamoDB, não são emulados, e os labs de BASE usam o MongoDB para isso.

## Diferenças que importam na migração

| Tema | LocalStack | Floci |
|---|---|---|
| imagem | `localstack/localstack` | `floci/floci` ou `floci/floci:<versão>-compat` |
| diretório de dados | `/var/lib/localstack` | `/app/data` |
| seleção de serviços | `SERVICES=sqs,s3,...` | todos sobem automaticamente |
| log | `LS_LOG` / `DEBUG=1` | `QUARKUS_LOG_LEVEL` |
| hostname nas URLs | `LOCALSTACK_HOST` | `FLOCI_HOSTNAME` (a variável antiga é traduzida) |
| Lambda | executor configurável | sempre em containers Docker |

## Alternativas consideradas

- **LocalStack**: exige conta desde março de 2026.
- **Emuladores isolados** (MinIO, ElasticMQ, DynamoDB Local): um container por serviço e mais memória; além disso, a imagem comunitária do MinIO deixou de ser distribuída em 2025.
