# Modos de falha e seus efeitos

Este documento é uma análise de modos de falha e efeitos (FMEA, na sigla em inglês), a técnica da engenharia de confiabilidade que pergunta, componente por componente: como isto pode falhar, o que a pessoa do outro lado sente, como o sistema percebe e o que ele faz a respeito. A versão daqui tem uma coluna a mais, a **prova**: cada linha aponta para o experimento, o laboratório ou o teste que mostra a reação funcionando, ou diz com todas as letras que essa prova ainda não existe.

| Tipo de prova | O que significa |
|---|---|
| experimento | um arquivo em [`chaos/experiments`](../../chaos/README.md) que qualquer pessoa roda com `make experiment e=<nome>` |
| laboratório | uma falha provocada, medida e contada em [`docs/labs`](../labs), com o que mudou no código |
| teste | um teste que roda no CI a cada PR |
| medição | uma falha provocada à mão, com o número registrado aqui, ainda sem experimento como código |

Os números vêm da stack local, em 28/09/2026. Eles mudam de máquina para máquina; a reação, não.

## Pagamento

| Modo de falha | Efeito para o cliente | Detecção | Reação | Prova | O que sobra |
|---|---|---|---|---|---|
| PSP lento, acima do timeout de 2 s | sem proteção, cada pagamento prende um processo do PHP-FPM, e o checkout inteiro fica lento | timeout por chamada; o circuit breaker conta as falhas no Redis | depois de cinco falhas, o circuito abre, e pagar responde `503` com `Retry-After` em milissegundos | experimento `psp-slow`; [laboratório do circuit breaker](../labs/circuit-breaker.md) | por 20 s, pagamentos que dariam certo são recusados; o cliente tenta de novo com a mesma chave |
| webhook do PSP perdido | o pedido fica em "Confirmando o pagamento" | a conciliação procura pagamentos sem desfecho depois de 60 s de silêncio | pergunta ao PSP e aplica a resposta pelo mesmo caminho do webhook | experimento `lost-psp-webhooks`: desfecho em 63,1 s sem nenhum webhook; [laboratório da conciliação](../labs/payment-reconciliation.md) | o desfecho atrasa cerca de um minuto |
| cobrança que some no PSP | o pagamento nunca teria resposta | a conciliação não encontra a cobrança | espera enquanto o pedido espera; quando a reserva vence, o pagamento vira `abandoned`, que ainda aceita a palavra tardia do PSP | laboratório da conciliação; [ADR 0019](../adr/0019-abandoned-payments-accept-late-outcomes.md) | uma aprovação tardia vira estorno |
| clique duplo, ou retry do cliente | cobrança em dobro | `Idempotency-Key` em todo `POST` | a mesma chave com o mesmo corpo devolve o mesmo resultado; um índice único parcial permite um só pagamento com sucesso por pedido | teste (`SchemaConstraintsTest` e os testes de idempotência) | nada |

## Bancos de dados

| Modo de falha | Efeito para o cliente | Detecção | Reação | Prova | O que sobra |
|---|---|---|---|---|---|
| PostgreSQL do commerce fora | não dá para fechar nem pagar um pedido | conexão recusada ou perdida | `503` com `Retry-After: 5`, e não mais `500` ([ADR 0026](../adr/0026-a-database-outage-is-unavailability.md)); o relay da outbox refaz a conexão; os consumidores esperam o banco em vez de mandar para a DLQ ([ADR 0017](../adr/0017-wait-for-the-database-not-the-dlq.md)) | experimento `commerce-database-out`: `503` em 0,13 s; [laboratório do banco fora do ar](../labs/database-outage.md) | pedidos recusados enquanto a queda durar; os eventos esperam na outbox |
| PostgreSQL da logistics fora | remessas novas esperam; o rastreio continua | a mesma | o rastreio lê a cópia no DynamoDB; o consumidor de `order.paid` espera o banco e cria a remessa quando ele volta | experimento `tracking-without-its-database`: rastreio em 0,03 s; laboratório: remessa criada 2 s depois da volta do banco | a cópia do rastreio para de andar durante a queda |
| MySQL do catálogo fora | a lista do catálogo não abre; um produto já em cache abre | conexão recusada | `503` com `Retry-After: 5`; o produto que está no Redis é servido pelo cache-aside | medição: lista em `503` em 0,67 s, produto em cache em `200` em 0,13 s; teste (`ProblemDetailsTest` do catálogo) | sem experimento como código ainda |
| a mesma unidade de estoque disputada | overselling | nenhuma: a regra impede antes | reserva dentro de uma transação, com uma de quatro estratégias corretas atrás de um port, e `CHECK (reserved <= on_hand)` no banco | [laboratório de overselling](../labs/overselling.md); teste de concorrência no CI | sob disputa muito alta, parte dos compradores recebe `503` (`stock-not-reserved`) e tenta de novo |
| pedido reservado e nunca pago | a unidade fica presa | o job de expiração lê os `pending_payment` vencidos | cancela o pedido e devolve o estoque | caso de uso [UC-ORD-03](../use-cases/UC-ORD-03-expire-unpaid-orders.md) e os testes dele | a unidade fica fora da prateleira por até 15 min |

## Mensageria

| Modo de falha | Efeito para o cliente | Detecção | Reação | Prova | O que sobra |
|---|---|---|---|---|---|
| Kafka fora | a remessa demora a nascer; a tela do pedido espera | o relay não consegue publicar | o evento espera na outbox, gravado na mesma transação do fato, e sai quando o Kafka volta; os consumidores retomam do último offset confirmado | [ADR 0008](../adr/0008-transactional-outbox.md) e os testes do pacote de mensageria | sem experimento como código ainda |
| evento entregue duas vezes | efeito em dobro | o id do evento, na inbox | a inbox descarta a repetição na mesma transação do efeito; `UNIQUE (order_id)` nas remessas | teste (pacote de mensageria e `SchemaConstraintsTest`) | nada |
| mensagem ilegível, ou recusada pelo domínio | a partição travaria atrás dela | `PermanentFailure` | vai para `dlq.<consumer-group>` na hora, com o erro nos headers | teste (pacote de mensageria) | alguém precisa olhar a DLQ |
| worker parado no meio de um retry | um efeito pela metade | `SIGTERM` | o offset não é confirmado, e a mensagem volta depois do restart | laboratório do banco fora do ar | nada |

## Parceiros e logística

| Modo de falha | Efeito para o cliente | Detecção | Reação | Prova | O que sobra |
|---|---|---|---|---|---|
| a transportadora perde webhooks | a jornada trava no meio | a conciliação compara com o histórico da transportadora; a vigia procura jornadas paradas | a conciliação traz os passos perdidos; a vigia avisa uma pessoa, uma vez só por jornada | [laboratório dos webhooks perdidos](../labs/lost-carrier-events.md) | depende de a transportadora guardar o histórico |
| o S3 falha ao guardar a etiqueta | a encomenda não sai do centro de distribuição | o job falha | tenta de novo com backoff; o que desiste vai para `failed_jobs`; o replay do Kafka recupera o que sumiu antes da fila; um `CHECK` impede a remessa de sair sem etiqueta | [laboratório da fila de etiquetas](../labs/label-queue.md) | o `failed_jobs` pede uma pessoa |

## A borda e a web

| Modo de falha | Efeito para o cliente | Detecção | Reação | Prova | O que sobra |
|---|---|---|---|---|---|
| o BFF perde o commerce | as telas de pedido | erro de rede no BFF | só as telas que dependem do commerce respondem `503` com `Retry-After`; o catálogo continua abrindo | experimento `commerce-cut-from-the-web`: `503` em 0,02 s | nada além da tela recusada |
| o catálogo fica lento para o BFF | a tela do catálogo | o prazo do BFF, `UPSTREAM_TIMEOUT_MS` (5 s) | desiste em 5 s, com `503` e `Retry-After`; a tela do pedido segue | experimento `catalog-slow-for-the-web`: `503` em 5,06 s | a pessoa espera 5 s antes da recusa |
| o BFF perde a logistics | a tela de rastreio | o mesmo mecanismo | `503` só nas telas da logistics | o mesmo mecanismo dos dois experimentos acima | sem experimento próprio |
| o Kong cai | nada responde | o health check do compose | nenhuma: no compose local, o Kong é um ponto único de falha | nenhuma | em produção, duas réplicas atrás de um balanceador |

## Infraestrutura de apoio

| Modo de falha | Efeito para o cliente | Detecção | Reação | Prova | O que sobra |
|---|---|---|---|---|---|
| Redis fora | o catálogo perde o cache, e o pagamento perde o circuit breaker | falha de conexão | o catálogo lê direto do MySQL; o breaker deixa as chamadas passarem, porque um breaker quebrado não pode derrubar o serviço | medição: catálogo em 1,13 s e produto em 0,21 s, com o Redis cortado; laboratório do circuit breaker | sem o breaker, um PSP lento ao mesmo tempo voltaria a prender processos; a janela que evita alerta repetido não foi medida |
| flagd fora | nenhum | a avaliação falha | cada avaliação tem um padrão seguro no código: caos desligado, estratégia `atomic` | [feature flags](feature-flags.md); [ADR 0015](../adr/0015-feature-flags-openfeature-flagd.md) | sem experimento como código ainda |
| MongoDB fora | nenhum na web; a lista de pedidos e a linha do tempo param de andar | os projetores falham | os projetores tentam de novo, com as tentativas contadas; uma projeção se refaz relendo o tópico, porque deduplica pelo id do evento | medição: rastreio e catálogo em `200` com o MongoDB cortado | pela leitura do código, uma queda longa manda mensagens boas para a DLQ (item 2 abaixo) |
| DynamoDB fora | o rastreio público não abre | a leitura falha sem resposta | a leitura tem uma tentativa só, de até 800 ms, e a página recusa na hora com `503` e `Retry-After: 5`; a escrita do projetor mantém as tentativas do SDK | experimento `tracking-without-its-copy`: `503` em 0,06 s, contra 5,05 s antes da correção | enquanto durar a queda, não há rastreio para mostrar |

## O que ainda não tem prova

Uma análise honesta termina pelo que falta. Estes são os próximos experimentos, em ordem de valor:

1. **MongoDB fora por muito tempo**: o retry sem limite dos consumidores só reconhece conexão perdida com o PostgreSQL. Pela leitura do código, o projetor da linha do tempo esgota as tentativas e manda para a DLQ mensagens que não têm defeito nenhum, o mesmo problema que o [ADR 0017](../adr/0017-wait-for-the-database-not-the-dlq.md) resolveu para o PostgreSQL. Falta o experimento que prove, e depois a correção.
2. **Kafka fora**: a outbox garante que nenhum evento se perde, mas nenhum experimento mede quanto tempo a remessa leva para nascer depois que o Kafka volta.
3. **Redis fora com o PSP lento**: as duas falhas juntas tiram a proteção do breaker. O experimento diria se o timeout de 2 s sozinho segura o checkout.
4. **O BFF sem a logistics**: o mecanismo é o mesmo do commerce, mas sem experimento próprio ele pode quebrar sem ninguém ver.
5. **O Kong**: aceitar o ponto único no ambiente local é uma decisão, e ela merece um ADR quando o projeto ganhar um ambiente com mais de uma máquina.
