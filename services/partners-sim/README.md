# partners-sim

Simulador do mundo de fora da Tucano, em Node 24 com Fastify 5 e TypeScript executado direto pelo Node. Ele faz o papel do PayFake (o PSP), das transportadoras parceiras e do app dos entregadores, para os serviços terem com quem conversar e para eu poder estragar essa conversa de propósito. Por enquanto é só o esqueleto: servidor, configuração por variáveis de ambiente, health checks, erros em problem details, correlation id e logs JSON. Ainda não simula ninguém.

## O que vem depois

- **PayFake**: cobrança com `Idempotency-Key` e o resultado entregue ao commerce por webhook, passando pelo Kong.
- **Transportadoras**: criação de envio e eventos de rastreio para o logistics, cada transportadora com o próprio formato, que o logistics traduz na borda (ACL).
- **Entregadores**: aparelhos simulados que mandam posição de GPS ao tracking por WebSocket e confirmam as entregas.
- **API de caos**: latência, erros, webhooks perdidos ou duplicados e entregas malsucedidas, ligados e desligados com o serviço rodando.

## Onde ele fica na rede

O `partners-sim` fica só na rede `edge`, que faz o papel da internet. Ele não alcança banco nem serviço interno e fala com a Tucano só pelo Kong, como um parceiro de verdade. No outro sentido, os serviços chegam até ele pelo Toxiproxy: `toxiproxy:14001` para o PayFake e `toxiproxy:14002` para as transportadoras. São dois proxies para o mesmo container, então dá para degradar o PSP sem mexer nas transportadoras. Os detalhes estão na [topologia local](../../docs/architecture/deployment.md).

## Por que Node aqui

A decisão está no [ADR 0014](../../docs/adr/0014-node-for-bff-and-partners.md). Simular parceiro é quase só I/O: receber a chamada, esperar, responder e disparar o webhook com atraso. Um processo Node de longa duração faz isso com timers e o event loop, e guarda na memória o estado dos controles de caos, já que da rede `edge` ele não enxerga banco nenhum.

## TypeScript sem build

Segue as mesmas regras do bff: o Node 24 executa os `.ts` direto (type stripping), sem etapa de build, e o `tsc` só checa tipos. As restrições de sintaxe que isso traz estão no [README do bff](../bff/README.md#typescript-sem-build).

## Como está organizado

| Caminho | O que tem |
|---|---|
| `src/server.ts` | ponto de entrada: lê a configuração, abre a porta e faz o graceful shutdown no `SIGTERM` e no `SIGINT` |
| `src/app.ts` | `buildApp()`, que monta o Fastify sem abrir porta |
| `src/config.ts` | variáveis de ambiente, com defaults e validação na subida |
| `src/platform/` | a cola com o Fastify: correlation id, logs, problem details, `DomainError` e health checks |
| `test/` | testes com `node:test` e `app.inject()`, sem rede |

O layout é o mesmo do [`bff`](../bff/README.md), e a pasta `src/platform/` é idêntica nos dois de propósito: a CI compara as cópias ([ADR 0016](../../docs/adr/0016-copied-node-platform.md)). Cada parceiro simulado vai entrar como uma pasta em `src/`, registrando suas rotas como plugin do Fastify.

## Endpoints

| Rota | O que faz |
|---|---|
| `GET /health/live` | o processo está de pé |
| `GET /health/ready` | as dependências respondem, com a latência de cada uma |

Não tem rota no Kong: quem chama o simulador são os serviços, pelo Toxiproxy. Por enquanto ele não depende de ninguém, então o ready responde `{"status":"up","checks":{}}`. Cada dependência nova entra como um check `{ name, check }` na lista que o `buildApp()` recebe. Os checks rodam em paralelo, e o ready responde 503 quando algum falha.

Todo erro sai como `application/problem+json` (RFC 9457) com os mesmos campos do commerce: `type`, `title`, `status`, `detail`, `instance` e `correlationId`. Falha de validação do JSON Schema da rota vira 422 com `errors`, a lista de mensagens por campo. Erro de domínio (`DomainError`) vira status pela categoria, como no PHP. Erro inesperado responde 500 com uma mensagem genérica, e o stack vai para o log junto com o correlation id. Rota desconhecida e URL malformada também saem como problem details.

O `X-Correlation-Id` que chega no request vira o id do request no Fastify (`requestIdHeader`). Sem o header, o simulador gera um UUIDv7. O id volta no header da resposta e aparece como `correlation_id` em toda linha de log do request.

Os logs são do pino que já vem no Fastify: uma linha JSON por evento, com `timestamp`, `level`, `service`, `message` e, dentro de um request, `correlation_id`. Os health checks não geram linha de request, porque o healthcheck do compose chama o ready a cada poucos segundos. Um ready que falha gera um `warn` com o estado de cada check.

## Configuração

| Variável | Default | Para quê |
|---|---|---|
| `SERVICE_NAME` | `partners-sim` | campo `service` dos logs |
| `APP_ENV` | `production` | `local`, `staging` ou `production`; qualquer outro valor roda como `production`, a mesma regra dos serviços PHP |
| `HOST` | `0.0.0.0` | interface onde o servidor escuta |
| `PORT` | `4000` | porta HTTP |
| `LOG_LEVEL` | `info` | `fatal`, `error`, `warn`, `info`, `debug`, `trace` ou `silent` |

Valor inválido impede a subida: o processo sai com código 1 e uma linha `fatal` que lista cada problema. Variável vazia conta como não definida.

## Rodando os checks

Para não depender do Node instalado na máquina, o `make` roda tudo no mesmo `node:24-alpine` da imagem:

```bash
make check s=partners-sim      # npm ci, lint, typecheck e testes
make logs s=partners-sim
```

| Script | O que faz |
|---|---|
| `npm run check` | lint, typecheck e testes, na mesma ordem do CI |
| `npm test` | `node --test`, que encontra sozinho os `.ts` de `test/` |
| `npm run typecheck` | `tsc --noEmit` |
| `npm run lint` | Biome: formatação, lint e ordem dos imports |
| `npm run format` | aplica as correções do Biome |
| `npm start` | sobe o servidor; `npm run dev` faz o mesmo e reinicia a cada mudança (`node --watch`) |

A imagem sai de `docker build -f services/partners-sim/Dockerfile -t chaos-playground/partners-sim .`, também da raiz. Ela leva só as dependências de produção, roda como o usuário `node` e trata o `SIGTERM` do `docker stop`: para de aceitar conexões, termina os requests em andamento e sai.
