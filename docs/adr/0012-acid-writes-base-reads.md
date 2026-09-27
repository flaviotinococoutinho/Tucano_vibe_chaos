# 0012. Escrita ACID em SQL, leitura BASE em NoSQL

- Status: aceito
- Data: 2026-09-27

## Contexto

Queremos mostrar lado a lado quando a consistência forte é obrigatória e quando a consistência eventual é aceitável, ou até desejável, pela escala e pela disponibilidade.

## Decisão

| Dado | Banco | Modelo | Por quê |
|---|---|---|---|
| pedidos, estoque, pagamentos, remessas | PostgreSQL 18 | ACID | dinheiro e estoque não toleram divergência; constraints e locks protegem as invariantes |
| catálogo | MySQL 8.4 | ACID | contraste de motor: `REPEATABLE READ` e *gap locks* do InnoDB contra o `READ COMMITTED` e o MVCC do PostgreSQL |
| histórico de pedidos e linha do tempo das remessas | MongoDB 8 | BASE | projeções desnormalizadas, alimentadas por eventos, que podem ficar alguns segundos atrás |
| rastreio público e log de notificações | DynamoDB (Floci) | BASE | acesso chave-valor em escala, com escrita condicional para deduplicar |
| cache, frota (GEO), locks, rate limit | Redis 8 | memória | latência baixa; a perda é tolerável ou recuperável |

## Consequências

- O laboratório de consistência mostra a mesma informação lida do PostgreSQL (forte) e do MongoDB (eventual), com o atraso visível.
- O laboratório de *overselling* compara estratégias de concorrência no mesmo código de produção.
- Mais bancos significam mais para operar. É o preço do estudo, e cada banco tem um papel que os outros não cumprem tão bem.
