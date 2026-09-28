# 8. Como apresentar o projeto

Um projeto de estudo só vale numa conversa se couber no tempo da conversa. Estes são os roteiros que eu uso, do elevador à entrevista técnica longa.

## Em um minuto

> Montei um e-commerce com entrega própria, a Tucano, para reaprender PHP moderno e Node depois de anos no Java e no Kotlin. São seis serviços num Docker Compose, conversando por Kafka com outbox e inbox, com PostgreSQL para escrever e MongoDB e DynamoDB para ler. A web é um intérprete de telas: o BFF manda cada tela em hipermídia, e o fluxo inteiro mora no servidor. E eu quebro tudo de propósito: o Toxiproxy fica entre cada serviço e cada dependência, os parceiros são simulados e aceitam comandos de caos, e cada laboratório conta o que mudou no código depois que a realidade discordou de mim.

## Uma demo de três minutos

```bash
make up                                   # a stack sobe e espera os health checks
open http://localhost:8000                # a loja
```

1. Compro um livro e pago com o cartão que aprova. A tela do pedido se atualiza sozinha: confirmando, pagamento aprovado, a caminho, entregue. O código de rastreio aparece quando a transportadora coleta.
2. Compro de novo e pago com o cartão recusado. A tela termina em "Cancelado", dizendo por quê, e o estoque volta para a prateleira.
3. Corto o commerce no Toxiproxy (`curl -s -X POST localhost:8474/proxies/bff-commerce -d '{"enabled": false}'`). A tela do pedido responde 503 dizendo quando tentar de novo, e o catálogo continua abrindo.
4. Mostro os logs: o `correlation_id` que o Kong carimbou no request aparece no BFF, no commerce e, pelo evento, no consumidor da logistics.

## Numa conversa técnica longa

Eu escolho o fio pela pessoa do outro lado:

| Se a conversa é sobre | Eu começo por | E aprofundo em |
|---|---|---|
| consistência de dados | a outbox e a inbox | idempotência de ponta a ponta, e o [ADR 0017](../adr/0017-wait-for-the-database-not-the-dlq.md) sobre esperar o banco em vez de mandar para a DLQ |
| resiliência | o [laboratório do circuit breaker](../labs/circuit-breaker.md) | a [conciliação de pagamentos](../labs/payment-reconciliation.md) e o estado `abandoned` |
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
| O que acontece se o PSP cair? | o circuit breaker abre e o checkout responde 503 com `Retry-After` em milissegundos | [circuit breaker](../labs/circuit-breaker.md) |
| E se o webhook do PSP nunca chegar? | a conciliação pergunta ao PSP e aplica a resposta pelo mesmo caminho do webhook | [conciliação](../labs/payment-reconciliation.md) |
| Por que não um microsserviço por entidade? | fronteira por subdomínio: o que muda junto fica junto | [ADR 0002](../adr/0002-services-per-subdomain.md) |
| Por que PHP e Node juntos? | cada linguagem onde o trabalho dela faz sentido, com as mesmas convenções nas duas | [stacks](02-stacks.md) |
| Como você muda um contrato de evento sem quebrar ninguém? | mudança compatível no mesmo tópico, incompatível num tópico novo (`v2`) | [ADR 0010](../adr/0010-cloudevents-contracts.md) |
| Como você impede a arquitetura de apodrecer? | cada regra importante é um teste que quebra o build | [abstrações](05-abstracoes.md) |
| Por que hipermídia e não GraphQL? | o fluxo fica no servidor e a web não precisa saber a ordem das telas | [ADR 0023](../adr/0023-server-driven-ui-with-siren.md) |
| Como você lida com a LGPD? | dado pessoal num proxy que se imprime mascarado, e minimização no que circula | [ADR 0024](../adr/0024-sensitive-data-behind-a-proxy.md) |
| O que você faria diferente num sistema de verdade? | login e sessão, OpenTelemetry no lugar do correlation id caseiro, CDC no lugar do relay, criptografia no armazenamento de dado pessoal, bulkheads no BFF | as possibilidades do capítulo [conceitos](06-conceitos.md) |

## O que eu digito antes de falar

Se eu tiver um minuto antes da conversa, abro três arquivos: o [CHANGELOG](../../CHANGELOG.md), para lembrar o que mudou por último; a lista de [ADRs](../adr/README.md), para lembrar do porquê; e um laboratório, porque número medido convence mais do que qualquer adjetivo.

Volte ao [início do guia](README.md).
