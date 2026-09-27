# 0014. Node.js no BFF e no simulador de parceiros

- Status: aceito
- Data: 2026-09-27

## Contexto

A web precisa de respostas agregadas de vários serviços e de atualizações em tempo real, e o laboratório precisa simular sistemas externos (PSP, transportadoras e o app da frota) com falhas controláveis.

## Decisão

- **`bff`** (Node 24 + Fastify): agrega chamadas em paralelo com timeout e circuit breaker, consome o Kafka e a fila de *push* e fala com o navegador por WebSocket.
- **`partners-sim`** (Node 24 + Fastify): simula o PayFake, as transportadoras e os entregadores, com uma API de caos para latência, erros, webhooks perdidos ou duplicados e entregas malsucedidas.
- TypeScript executado direto pelo Node (*type stripping*), sem etapa de build.
- Kafka via `@confluentinc/kafka-javascript`, que usa a mesma `librdkafka` da extensão PHP; o KafkaJS está sem manutenção desde 2023.

## Consequências

- O PHP conversa com o Node nos dois sentidos: o BFF chama os serviços PHP, e os serviços PHP chamam o `partners-sim`, que responde por webhooks via Kong.
- No Node, o estado do circuit breaker fica em memória, porque o processo é longo. No PHP-FPM ele precisa ir para o Redis, e essa diferença é material de estudo.
