# logistics

Serviço de remessas da Tucano, em Laravel 13 sobre PHP 8.4 (FPM). Aqui fica o núcleo logístico: criar a remessa quando o pedido é pago, escolher a transportadora, gerar a etiqueta e conduzir a entrega pela máquina de estados até o cliente (ou de volta ao CD).

A estrutura é a mesma do [commerce](../commerce/README.md), de propósito: dois núcleos, um jeito só de organizar.

## Como está organizado

| Pasta | O que tem |
|---|---|
| `src/` | código de negócio por subdomínio (package-by-feature), cada um com seu hexágono. Veja [`src/README.md`](src/README.md) |
| `app/` | cola com o Laravel: health checks, correlation id, problem details e o provider de plataforma |
| `config/platform.php` | flags, Snowflake (worker 11) e nome do serviço |
| `tests/Architecture` | fitness functions (casos de uso documentados) |
| `deptrac.yaml` | regra de dependência entre as camadas |

## Endpoints

| Rota | Pelo Kong | O que faz |
|---|---|---|
| `GET /health/live` | `/api/logistics/health/live` | o processo está de pé |
| `GET /health/ready` | `/api/logistics/health/ready` | PostgreSQL e Redis respondem, com a latência de cada um |

Erros saem como `application/problem+json` (RFC 9457), no mesmo formato do commerce.

## Rodando

```bash
make up
make check s=logistics
make logs s=logistics
```

O banco é o `logistics`, no mesmo PostgreSQL do commerce mas com role própria: o role `logistics` não consegue nem conectar no banco do commerce. É database-per-service numa instância compartilhada, com o isolamento garantido por permissão.
