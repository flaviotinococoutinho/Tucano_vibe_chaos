# Laboratório: overselling

Cinco unidades do console, oito pessoas apertando "comprar" no mesmo instante. Quantas levam?

A resposta depende de como o estoque é reservado. O commerce tem cinco estratégias atrás do mesmo port (`ForHoldingStock`), e a pergunta deste laboratório é o que cada uma faz quando a disputa é de verdade.

## As estratégias

| Estratégia | Como segura a unidade | Custo |
|---|---|---|
| `atomic` (padrão) | `UPDATE stock_items SET reserved = reserved + n WHERE ... AND on_hand - reserved >= n`: confere e grava numa instrução só | quase nenhum; é a que uso em produção |
| `pessimistic` | `SELECT ... FOR UPDATE`, confere no PHP, depois `UPDATE` | quem chega depois espera na fila do lock; produto quente vira fila |
| `optimistic` | lê sem lock e grava com `WHERE version = <versão lida>`; se alguém mudou a linha, lê de novo | ninguém espera, mas sob disputa a maioria das tentativas é desperdiçada; desiste depois de 5 rodadas com `409` |
| `serializable` | lê, decide no PHP e grava o novo total, em transação `SERIALIZABLE` | o PostgreSQL recusa a transação perdedora (`40001`) e o caso de uso roda de novo, até 5 vezes |
| `naive` | exatamente o mesmo código do `serializable`, em `READ COMMITTED` | perde atualizações; existe só para ser vista falhando |

O `serializable` e o `naive` usam a mesma classe (`ReadThenWriteStockHolder`). A diferença entre vender certo e vender errado ali é só o nível de isolamento da transação.

### Por que o `atomic` funciona

Em `READ COMMITTED`, o `UPDATE` trava a linha antes de escrever. Se outra transação mudou a linha e ainda não terminou, este `UPDATE` espera; quando a outra faz commit, o PostgreSQL avalia o `WHERE` de novo contra a versão nova da linha (EvalPlanQual). O comprador que chega com a última unidade já tomada vê `on_hand - reserved = 0` e não afeta nenhuma linha.

### Por que o `naive` não dispara o `CHECK`

A tabela tem `CHECK (reserved <= on_hand)`, e mesmo assim o `naive` vende mais do que tem. Oito compradores leem `reserved = 0` quase ao mesmo tempo e cada um grava `reserved = 1`: oito reservas, uma unidade presa. O número gravado continua válido, então o `CHECK` não tem o que recusar. O que quebra é a invariante que o banco não enxerga numa linha só: `reserved` = soma das reservas ativas em `stock_reservations`.

## O que medi

20 rodadas, 5 unidades por rodada, compradores liberados ao mesmo tempo, cada um num processo com a própria conexão:

| Estratégia | 8 compradores: reservas / recusas / desistências | 30 compradores: reservas / recusas | Rodadas com `reserved` errado |
|---|---|---|---|
| `atomic` | 100 / 60 / 0 | 100 / 500 | 0 |
| `pessimistic` | 100 / 60 / 0 | 100 / 500 | 0 |
| `optimistic` | 100 / 60 / 0 | 100 / 500 | 0 |
| `serializable` | 100 / 53 / 7 | 100 / 500 | 0 |
| `naive` | 160 / 0 / 0 | 483 / 117 | 20 de 20 |

As estratégias corretas venderam exatamente as 100 unidades das 20 rodadas. No `serializable`, 7 compradores desistiram depois de cinco recusas seguidas do PostgreSQL, mas as unidades acabaram vendidas para os outros. O `naive` aceitou todos os oito compradores em todas as rodadas e, com 30 compradores, segurou 483 reservas para 100 unidades.

## Como rodar

O `ConcurrentReservationsTest` roda essa corrida no `make check s=commerce` (com `pcntl_fork`, oito processos por estratégia) e falha se alguma estratégia correta vender uma unidade duas vezes. O `naive` fica fora do teste de propósito: o lost update depende de tempo, e um teste que só às vezes falha não prova nada.

Na stack, a estratégia vem da flag `inventory.reservation-strategy`. Com `labs.enabled` ligada (só em local e staging; o `ProductionGuard` desliga `labs.*` em produção), a preferência `reservation-strategy` do header `Prefer` escolhe a estratégia de um request:

```bash
curl -si -X POST localhost:8000/api/commerce/v1/orders \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -H 'Prefer: reservation-strategy=naive' \
  -d @pedido.json | grep -i preference-applied
```

O `pedido.json` é o corpo do exemplo de pedido do README, com o `LAB-CONSOLE-001`, o console de edição limitada, e `"store": "bemtevi"`: desde as lojas ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)), todo pedido diz de que loja é, e o console é da Bem-te-vi.

O `Prefer` é o header do RFC 7240 para exatamente isso: uma dica de como o cliente gostaria que o servidor se comportasse, que o servidor pode seguir ou ignorar. Quando o laboratório segue a dica, a resposta traz `Preference-Applied: reservation-strategy=naive`; fora do laboratório, ou com um nome de estratégia que não existe, a dica é ignorada e a resposta não diz nada. Antes era um header `X-Inventory-Strategy`, e o RFC 6648 desaconselha o prefixo `X-` em nome novo.

A estratégia escolhida entra no contexto do request e aparece em toda linha de log dele (`inventory_strategy`). A carga concorrente de verdade pela API, com k6 e a conferência da invariante, entra junto com o laboratório de caos.
