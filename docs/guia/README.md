# Guia do chaos playground

Este guia é a porta de entrada amigável do projeto. O resto de `docs/` é a referência: ADRs, casos de uso, laboratórios, modelo de dados. Aqui eu conto a história na ordem em que ela faz sentido, com o porquê antes do como, e aponto para a referência quando você quiser o detalhe.

Cada capítulo cabe numa leitura de café. Dá para ler de cima para baixo ou pular direto para o que interessa.

| Capítulo | Para quando você quer saber |
|---|---|
| [1. A Tucano e a jornada de um pedido](01-a-tucano.md) | o que o sistema faz, para quem, e os casos de uso que o sustentam |
| [2. Stacks e ferramentas transversais](02-stacks.md) | por que PHP aqui, Node ali, e o que Kafka, Kong, Toxiproxy e companhia fazem no meio |
| [3. A natureza da informação](03-informacao.md) | o que é fonte da verdade, o que é cópia, e por que uns dados são ACID e outros BASE |
| [4. O playground do caos](04-caos.md) | como eu quebro as coisas de propósito e o que cada laboratório ensinou |
| [5. Abstrações que seguram a entropia](05-abstracoes.md) | como o código continua fácil de mudar conforme cresce, e quem cobra isso |
| [6. Conceitos, ganhos e custos](06-conceitos.md) | cada ideia que o projeto usa, com o que ela dá, o que ela cobra e para onde pode ir |
| [7. Padrões e RFCs](07-padroes.md) | os padrões que o projeto segue, onde cada um aparece e por quê |
| [8. Como apresentar o projeto](08-como-apresentar.md) | o roteiro de um minuto, de cinco e de uma conversa técnica longa |

## O projeto em uma frase

A Tucano é um e-commerce fictício com entrega própria, e o chaos playground é o lugar onde eu construo esse sistema do jeito que faria no trabalho, para depois quebrar de propósito e medir o que acontece. Ele calibra e relembra stacks: PHP moderno com Laravel, Lumen e Swoole, Node com Fastify e React, e a caixa de ferramentas de sistemas distribuídos que eu uso há anos no Java e no Kotlin.

## Três ideias que atravessam tudo

- **Nada de bala de prata.** Toda escolha aqui tem um ganho e um custo escritos. Quando uma decisão parece de graça, eu ainda não entendi o que ela cobra.
- **Integridade conceitual.** O mesmo problema tem a mesma solução em todo lugar: um jeito de configurar, um jeito de errar (RFC 9457), um jeito de publicar evento (outbox), um jeito de chamar outro contexto (port e adapter). Fred Brooks dizia que essa é a consideração mais importante no desenho de um sistema, e eu concordo.
- **A documentação é cobrada pelo CI.** Configuração, casos de uso, contratos e fronteiras entre módulos têm fitness functions. Quando o código e o papel se desencontram, o build quebra.
