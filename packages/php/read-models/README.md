# tucano/read-models

Peças para os read models no MongoDB (o lado de leitura do CQRS) de commerce e logistics.

| Peça | O que faz |
|---|---|
| `Migration` e `MongoMigrator` | migrations de schema do MongoDB: coleções com validador `$jsonSchema` e índices, aplicadas em ordem e registradas em `_migrations`, como o Laravel faz no SQL |
| `VersionedDocuments` | last-writer-wins por versão: aplica uma mudança só se ela for mais nova que o documento, então evento repetido ou atrasado não estraga a projeção |

Cada serviço guarda as suas migrations em `database/mongo` e roda `php artisan mongo:migrate` no mesmo job que aplica o SQL.

## Por que versionar a projeção

Os eventos de um pedido chegam por dois tópicos (`commerce.orders.v1` e `logistics.shipments.v1`) e podem ser reentregues. Não existe ordem garantida entre tópicos. Guardando a versão no documento e filtrando por `version < nova`, a projeção converge para o estado certo, seja qual for a ordem de chegada. Isso é consistência eventual com convergência, e não "qualquer coisa eventualmente".

## Testes

```bash
make packages-check
docker run --rm --network chaos-playground_backend -v "$PWD":/app -w /app/packages/php/read-models \
  -e READ_MODELS_MONGO_URI='mongodb://mongo:27017/?directConnection=true' chaos-playground/php-base:8.4 vendor/bin/phpunit
```
