# 8. Como apresentar o projeto

Um projeto de estudo só vale numa conversa se couber no tempo da conversa. Estes são os roteiros que eu uso, do elevador à entrevista técnica longa.

## Em um minuto

> Passei um tempo usando mais Java, Kotlin e os frameworks em volta deles, e montei a Tucano, um e-commerce com entrega própria, para reencontrar o PHP e o Node que eu conhecia de perto e ver o quanto eles evoluíram junto com as ferramentas que atravessam qualquer linguagem. São seis serviços num Docker Compose, conversando por Kafka com outbox e inbox, com PostgreSQL para escrever e MongoDB e DynamoDB para ler. A web é um intérprete de telas: o BFF manda cada tela em hipermídia, e o fluxo inteiro mora no servidor. E eu quebro tudo de propósito: o Toxiproxy fica entre cada serviço e cada dependência, os parceiros são simulados e aceitam comandos de caos, e cada laboratório conta o que mudou no código depois que a realidade discordou de mim. Seis experimentos de caos rodam com um comando, e um deles pegou um bug enquanto eu escrevia a documentação.

## Uma demo de três minutos

```bash
make up                                   # a stack sobe e espera os health checks
open http://localhost:8000                # a loja
```

1. Abro a Tucano, mostro as três lojas, cada uma na sua paleta, e entro na Arara. Compro um livro e pago com o cartão que aprova. A tela do pedido se atualiza sozinha e vai contando a história: as cinco etapas, do pedido feito à entrega, e o histórico que junta o que o commerce e a logística sabem. Na saída para entrega, o entregador aparece ao vivo na própria tela.
2. Compro de novo e pago com o cartão recusado. A tela termina em "Cancelado", dizendo por quê, e o estoque volta para a prateleira.
3. Troco de perfil no topo e crio o Bruno. "Meus pedidos" dele está vazio, e a URL de um pedido da Ana responde "Não encontrado". Volto para a Ana, e os pedidos dela estão lá. Depois abro o mesmo pedido pelo caminho da Sabiá: "Não encontrado" de novo, porque ele é da Arara.
4. Rodo `make experiment e=a-store-in-a-rush`: quarenta compradores lotam a Arara, e a Sabiá segue abrindo em menos de 0,1 s, porque cada loja gasta o próprio limite no Kong.
5. Rodo `make experiment e=commerce-cut-from-the-web`. O experimento diz a hipótese antes de quebrar qualquer coisa, corta o commerce da web, confere a tela do pedido (503 dizendo quando tentar de novo) e o catálogo (continua abrindo), e desfaz tudo no final.
6. Conto a história do `commerce-database-out`: eu ia escrever que a loja recusa com honestidade quando o banco cai, medi antes, e ela respondia um 500 mudo. O experimento pegou, a correção virou o [ADR 0026](../adr/0026-a-database-outage-is-unavailability.md).
7. Mostro os logs: o `correlation_id` que o Kong carimbou no request aparece no BFF, no commerce e, pelo evento, no consumidor da logistics.

## Numa conversa técnica longa

Eu escolho o fio pela pessoa do outro lado:

| Se a conversa é sobre | Eu começo por | E aprofundo em |
|---|---|---|
| consistência de dados | a outbox e a inbox | idempotência de ponta a ponta, e o [ADR 0017](../adr/0017-wait-for-the-database-not-the-dlq.md) sobre esperar o banco em vez de mandar para a DLQ |
| resiliência | o [laboratório do circuit breaker](../labs/circuit-breaker.md) | a [conciliação de pagamentos](../labs/payment-reconciliation.md), o estado `abandoned`, e o [mapa de modos de falha](../architecture/failure-modes.md) |
| engenharia do caos | os [experimentos como código](../../chaos/README.md) | o experimento que me corrigiu, e por que um rollback precisa devolver o estado estável, não só tirar a falha |
| dados | [como eu penso](00-como-eu-penso.md): fato, conta e cópia | até onde normalizar, o que vai para o banco como `CHECK`, e o CAP escolhido por operação |
| concorrência | o [laboratório de overselling](../labs/overselling.md), com os números | por que o `serializable` desistiu de sete compradores e ainda vendeu tudo |
| arquitetura | o hexágono por pacote e o Deptrac | as fitness functions, e por que eu copio a plataforma Node em vez de compartilhar |
| front-end | a web como intérprete de hipermídia | o contrato Siren, os exemplos que viram teste nos dois lados, e a acessibilidade |
| segurança e privacidade | o `Sensitive` e o teste que só aceita `reveal()` onde deve | o número de cartão que antes voltava na resposta de erro |

## Perguntas que eu espero, e onde está a resposta

| Pergunta | Resposta curta | Onde |
|---|---|---|
| Como você garante que um evento não se perde? | ele entra na outbox na mesma transação do fato, e um relay publica depois | [ADR 0008](../adr/0008-transactional-outbox.md) |
| E se o Kafka entregar duas vezes? | o consumidor marca o id na inbox na mesma transação do efeito | [eventos](../architecture/events.md) |
| Como você não vende a mesma unidade duas vezes? | reserva dentro de uma transação, com uma de quatro estratégias corretas atrás de um port | [overselling](../labs/overselling.md) |
| E se o banco cair? | o pedido recusa na hora, com `503` e `Retry-After`, e o rastreio continua respondendo pela cópia | [ADR 0026](../adr/0026-a-database-outage-is-unavailability.md) e o [capítulo da informação](03-informacao.md#quando-a-rede-se-parte) |
| O que acontece se o PSP cair? | o circuit breaker abre e o checkout responde 503 com `Retry-After` em milissegundos | [circuit breaker](../labs/circuit-breaker.md) |
| E se o webhook do PSP nunca chegar? | a conciliação pergunta ao PSP e aplica a resposta pelo mesmo caminho do webhook | [conciliação](../labs/payment-reconciliation.md) |
| Por que não um microsserviço por entidade? | fronteira por subdomínio: o que muda junto fica junto | [ADR 0002](../adr/0002-services-per-subdomain.md) |
| Por que PHP e Node juntos? | cada linguagem onde o trabalho dela faz sentido, com as mesmas convenções nas duas | [stacks](02-stacks.md) |
| Como você faz multi-tenancy? | modelo de pool: serviços, bancos e tópicos compartilhados, com a loja em todo dado que é dela; cada serviço cobra o isolamento no próprio dado, e o Kong dá a cada loja o seu limite | [ADR 0031](../adr/0031-a-store-is-a-tenant.md) e [ADR 0032](../adr/0032-the-edge-per-store-limits-and-its-single-point.md) |
| Como um cliente não vê o pedido do outro? | a sessão é assinada pelo BFF, o commerce filtra cada leitura pelo cliente e responde o mesmo 404 para um pedido alheio, e a borda só alcança as rotas públicas do commerce, por uma porta do nginx que não serve as do cliente | [ADR 0030](../adr/0030-each-customer-sees-only-its-orders.md) |
| Como você muda um contrato de evento sem quebrar ninguém? | mudança compatível no mesmo tópico, incompatível num tópico novo (`v2`) | [ADR 0010](../adr/0010-cloudevents-contracts.md) |
| Como você impede a arquitetura de apodrecer? | cada regra importante é um teste que quebra o build | [abstrações](05-abstracoes.md) |
| Por que hipermídia e não GraphQL? | o fluxo fica no servidor e a web não precisa saber a ordem das telas | [ADR 0023](../adr/0023-server-driven-ui-with-siren.md) |
| Como você lida com a LGPD? | dado pessoal num proxy que se imprime mascarado, e minimização no que circula | [ADR 0024](../adr/0024-sensitive-data-behind-a-proxy.md) |
| O que você faria diferente num sistema de verdade? | um provedor de identidade no lugar dos perfis sem senha, OpenTelemetry no lugar do correlation id caseiro, CDC no lugar do relay, criptografia no armazenamento de dado pessoal, bulkheads no BFF | as possibilidades do capítulo [conceitos](06-conceitos.md) |

## O que eu digito antes de falar

Se eu tiver um minuto antes da conversa, abro três arquivos: o [CHANGELOG](../../CHANGELOG.md), para lembrar o que mudou por último; a lista de [ADRs](../adr/README.md), para lembrar do porquê; e um laboratório, porque número medido convence mais do que qualquer adjetivo.

Volte ao [início do guia](README.md).
