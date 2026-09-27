# commerce

Serviço de pedidos, estoque, pagamentos e notificações da Tucano, em Laravel 13 sobre PHP 8.4 (FPM). É o núcleo transacional: tudo que envolve dinheiro e estoque passa por aqui, com escrita ACID no PostgreSQL.

## Como está organizado

| Pasta | O que tem |
|---|---|
| `src/` | código de negócio, um pacote por subdomínio (package-by-feature), cada um com seu hexágono. Veja [`src/README.md`](src/README.md) |
| `app/` | só a cola com o Laravel: health checks, correlation id, problem details e o provider de plataforma |
| `config/platform.php` | flags, Snowflake e nome do serviço |
| `tests/Architecture` | fitness functions (casos de uso documentados) |
| `deptrac.yaml` | regra de dependência entre as camadas |

O Laravel serve de framework de entrega: roteamento, container de injeção, fila e console. Nada de Eloquent no domínio.

## Endpoints

| Rota | Pelo Kong | O que faz |
|---|---|---|
| `GET /health/live` | `/api/commerce/health/live` | o processo está de pé |
| `GET /health/ready` | `/api/commerce/health/ready` | PostgreSQL e Redis respondem, com a latência de cada um |
| `POST /v1/orders` | `/api/commerce/v1/orders` | faz um pedido ([UC-ORD-01](../../docs/use-cases/UC-ORD-01-place-order.md)): confere o catálogo local, reserva o estoque ([UC-INV-01](../../docs/use-cases/UC-INV-01-reserve-stock.md)) e grava `OrderPlaced` na outbox; exige `Idempotency-Key` |
| `GET /v1/orders/{orderId}` | `/api/commerce/v1/orders/{orderId}` | consulta um pedido ([UC-ORD-05](../../docs/use-cases/UC-ORD-05-view-orders.md)) |

O `POST /v1/orders` responde `201` com o pedido e um `Location` relativo (`orders/{id}`), que resolve certo tanto direto no serviço quanto atrás do prefixo `/api/commerce` do Kong. Repetir o request com a mesma `Idempotency-Key` devolve a mesma resposta com `Idempotent-Replayed: true`; a mesma chave com outro corpo recebe `422`.

Todo erro sai como `application/problem+json` (RFC 9457) com o `correlationId` do request. Erros de domínio viram status pela categoria (`NotFound` 404, `Conflict` 409, `InvalidInput` 422, `Forbidden` 403, `Unavailable` 503) e não vão para o log de erro, porque são respostas esperadas, não incidentes.

## Workers

Os workers usam a mesma imagem da API, cada um com um comando de longa duração, e param com `SIGTERM` depois de terminar o que estão fazendo. O compose declara `stop_signal: SIGTERM` porque a imagem base herda o `SIGQUIT` do PHP-FPM.

| Serviço no compose | Comando | O que faz |
|---|---|---|
| `commerce-outbox-relay` | `php artisan commerce:relay-outbox` | publica a outbox no Kafka com `FOR UPDATE SKIP LOCKED`; a flag `chaos.commerce.outbox-relay-paused` pausa a publicação sem derrubar o processo, e os eventos se acumulam na outbox até a flag voltar |
| `commerce-order-expiry` | `php artisan commerce:expire-orders` | cancela pedidos não pagos com a reserva vencida e devolve o estoque ([UC-ORD-03](../../docs/use-cases/UC-ORD-03-expire-unpaid-orders.md)); várias cópias dividem o trabalho com `SKIP LOCKED` |
| `commerce-catalog-sync` | `php artisan commerce:sync-catalog` | mantém `product_snapshots` a partir de `catalog.products.v1` ([UC-ORD-06](../../docs/use-cases/UC-ORD-06-sync-catalog.md)); snapshot ilegível vai para `dlq.commerce.catalog-sync` |

## Rodando

```bash
make up                  # sobe a stack com o commerce
make check s=commerce    # Pint, Larastan, Deptrac e PHPUnit contra a stack
make logs s=commerce
```

A configuração vem de variáveis de ambiente definidas no `compose.yaml`. O container roda `config:cache`, `route:cache` e `event:cache` na subida, e não no build, para não congelar o ambiente de quem construiu a imagem.

## Decisões do runtime

- **Snowflake no FPM**: cada filho do pool é um processo isolado, então a sequência fica no APCu (`ApcuSequence`). Workers de CLI usam `InMemorySequence`.
- **Flags**: `Flagd::connect` com cache em APCu no FPM e em memória no CLI. Ambiente desconhecido conta como produção.
- **Dependências passam pelo Toxiproxy** (`DB_HOST=toxiproxy`, `DB_PORT=15432`), para os experimentos de caos funcionarem sem mudar nada no serviço.
