# 0032. O Kong: a borda só com o que é público, um limite por loja e o ponto único que eu aceito

- Status: aceito
- Data: 2026-09-29

## Contexto

O Kong é a porta de tudo desde o [ADR 0006](0006-kong-db-less.md), em modo DB-less. O [mapa de modos de falha](../architecture/failure-modes.md) registrava que ele é um ponto único no compose, e que isso merecia um ADR quando o projeto ganhasse um ambiente com mais de uma máquina. Duas coisas desta rodada anteciparam o assunto.

A primeira foi uma revisão. Fechar as rotas do cliente com uma regra de caminho no Kong não bastou: `/api/commerce/v1%2Fcustomers` passou por ela e respondeu os pedidos de outra pessoa ([ADR 0030](0030-each-customer-sees-only-its-orders.md)).

A segunda foram as lojas ([ADR 0031](0031-a-store-is-a-tenant.md)). Elas dividem o BFF, o catálogo e os bancos, e uma loja em promoção pode esgotar a capacidade de todas. Medi isso antes de decidir qualquer coisa: quarenta compradores abrindo o catálogo da Arara por 15 s, uns 250 requests por segundo, levaram o p95 da vizinha Sabiá de menos de 0,1 s, parada, para 0,40 a 0,48 s.

## Decisão

- **A borda só alcança o que é público.** Cada serviço decide o que expõe numa porta de borda própria, com uma lista de rotas permitidas, e o Kong aponta para ela. O commerce foi o primeiro, na porta 8182 do nginx: as rotas internas nem existem ali.
- **Cada loja é um serviço no Kong, com o seu limite.** Os serviços `bff-store-arara`, `bff-store-bemtevi` e `bff-store-sabia` apontam para o mesmo BFF, cada um com o plugin `rate-limiting` em `limit_by: service` e 20 requests por segundo, o bastante para o laboratório. O contador é da loja inteira, venha o request de quem vier, e quem passa do limite recebe 429 com `Retry-After`. As telas da plataforma (as lojas, os perfis e a busca de um código de rastreio) seguem pela rota `/bff`, sem limite de loja.
- **O ponto único fica, no ambiente local.** Um Kong só: numa máquina só, uma segunda cópia cairia junto com a primeira. Num ambiente com mais máquinas, o desenho é duas ou mais réplicas atrás de um balanceador de camada 4, e o limite troca `policy: local` por `policy: redis`, para contar a loja inteira, e não cada réplica.

## Consequências

- Com o limite, na mesma promoção, a Arara recebeu 344 respostas 200 e 563 recusas 429, e a Sabiá manteve o p95 em 0,08 s. O experimento `a-store-in-a-rush` defende esse comportamento: sem o limite, ele desvia.
- O limite protege o que fica atrás do Kong, não o próprio Kong. Com compradores que repetem na hora, sem esperar o `Retry-After`, foram 24 mil recusas em 15 s, e a vizinha ainda sentiu (p95 de 0,21 s): a borda virou o gargalo. Por isso o experimento faz os compradores se comportarem como navegadores, que esperam o que o 429 manda, e por isso um ambiente de verdade pede réplicas e proteção de conexão na frente do Kong.
- Uma loja nova é um cadastro no catálogo e um serviço no Kong. Esquecer o serviço não quebra a loja: ela segue pela rota `/bff`, só que sem limite próprio.
- O 429 do Kong é um JSON dele, `{"message": ...}`, e não um problem details. A web mostra "Muitas tentativas" pelo status.
- Uma loja em promoção de verdade tem clientes recusados quando passa do limite. É a troca de todo limite por tenant: a loja em pico paga o pico dela, e não o das vizinhas.
- Com o Kong fora, nada responde, como antes. Não escrevi um experimento para isso: ele só confirmaria o óbvio, e a reação que falta é de infraestrutura.

## Alternativas consideradas

- **Limitar no BFF**: o BFF é que pagaria o trabalho de recusar. Na borda, o request recusado nem chega a ele.
- **Limitar por IP**: protege de um cliente abusivo, não de uma loja inteira em promoção, com milhares de compradores.
- **A loja num header, com `limit_by: header`**: o header viria do navegador, e quem manda o header escolhe o contador.
- **Um limite só para o BFF inteiro**: a promoção de uma loja gastaria o limite de todas, que é justamente o problema.
- **Uma regra de caminho no Kong para esconder as rotas internas**: foi a primeira versão, e a barra codificada passou por ela. Uma lista de bloqueio erra aberta; uma de rotas permitidas erra fechada.
- **Duas réplicas do Kong já no compose**: sem um balanceador na frente e numa máquina só, a segunda réplica não resolve o ponto único, e custa memória num laboratório de 8 GB.
