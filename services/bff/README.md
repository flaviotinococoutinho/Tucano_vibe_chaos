# bff

Backend for frontend da web da Tucano, em Node 24 com Fastify 5 e TypeScript executado direto pelo Node. Por enquanto é só o esqueleto: servidor, configuração por variáveis de ambiente, health checks, erros em problem details, correlation id e logs JSON. Ainda não tem rota de negócio.

## O que vem depois

- **Agregação**: uma rota por tela, que chama catalog, commerce e logistics em paralelo, cada chamada com timeout e circuit breaker, e devolve só o que a tela usa.
- **Tempo real**: o consumer group `bff.live` lê `commerce.orders.v1` e `logistics.shipments.v1` e empurra as mudanças para o navegador por WebSocket. Os avisos da fila `push-notifications` seguem o mesmo caminho.
- **Flags de interface**: avaliadas in-process com o provider do flagd. A web recebe só o valor já resolvido.

O BFF não tem domínio próprio. No [context map](../../docs/architecture/context-map.md) ele é Conformist: adota o modelo de quem consulta e só combina as respostas para a tela.

## Por que Node aqui

A decisão está no [ADR 0014](../../docs/adr/0014-node-for-bff-and-partners.md). O trabalho do BFF é esperar I/O: várias chamadas em paralelo por request e conexões WebSocket que ficam abertas. O event loop de um processo Node de longa duração resolve isso sem um processo por conexão, e o estado do circuit breaker fica na memória. No PHP-FPM cada request começa do zero, então esse estado teria que ir para o Redis. Comparar os dois lados faz parte do estudo.

## TypeScript sem build

Não existe etapa de build. O Node 24 executa os arquivos `.ts` direto: antes de rodar, ele troca as anotações de tipo por espaços em branco (type stripping). Linhas e colunas não mudam, então o stack trace aponta para o lugar certo sem source map, e o código que roda é exatamente o que está no repositório.

O preço é usar só sintaxe que some sem deixar código para trás:

- Nada de `enum`, `namespace` com código, parameter properties (`constructor(private readonly x: X)`) nem decorators. Para conjuntos fechados uso union de string literal ou objeto `as const`; para estados, discriminated unions.
- Import relativo com a extensão real (`./app.ts`) e `import type` para o que é só tipo. O Node não sabe o que é tipo e o que é valor, então não tem como adivinhar.
- O Node não lê o `tsconfig.json` e não checa tipo nenhum. Quem checa é o `tsc --noEmit`, com `erasableSyntaxOnly` recusando a sintaxe que o Node não executaria.
- Arquivo `.ts` dentro de `node_modules` não roda, então toda dependência precisa publicar JavaScript. Path alias do `tsconfig` e JSX também ficam de fora.

O `tsc` é o TypeScript 7, o compilador nativo escrito em Go. Ele só checa tipos e não gera nada, então a versão do compilador não muda o que roda.

## Como está organizado

| Caminho | O que tem |
|---|---|
| `src/server.ts` | ponto de entrada: lê a configuração, abre a porta e faz o graceful shutdown no `SIGTERM` e no `SIGINT` |
| `src/app.ts` | `buildApp()`, que monta o Fastify sem abrir porta |
| `src/config.ts` | variáveis de ambiente, com defaults e validação na subida |
| `src/platform/` | a cola com o Fastify: correlation id, logs, problem details, `DomainError` e health checks |
| `test/` | testes com `node:test` e `app.inject()`, sem rede |

O layout é o mesmo do [`partners-sim`](../partners-sim/README.md), e a pasta `src/platform/` é idêntica nos dois de propósito: a CI compara as cópias ([ADR 0016](../../docs/adr/0016-copied-node-platform.md)). As features vão entrar como pastas em `src/`, cada uma registrando suas rotas como plugin do Fastify.

## Endpoints

| Rota | Pelo Kong | O que faz |
|---|---|---|
| `GET /health/live` | `/bff/health/live` | o processo está de pé |
| `GET /health/ready` | `/bff/health/ready` | as dependências respondem, com a latência de cada uma |

Por enquanto o BFF não depende de ninguém, então o ready responde `{"status":"up","checks":{}}`. Cada dependência nova entra como um check `{ name, check }` na lista que o `buildApp()` recebe. Os checks rodam em paralelo, e o ready responde 503 quando algum falha.

Todo erro sai como `application/problem+json` (RFC 9457) com os mesmos campos do commerce: `type`, `title`, `status`, `detail`, `instance` e `correlationId`. Falha de validação do JSON Schema da rota vira 422 com `errors`, a lista de mensagens por campo. Erro de domínio (`DomainError`) vira status pela categoria, como no PHP. Erro inesperado responde 500 com uma mensagem genérica, e o stack vai para o log junto com o correlation id. Rota desconhecida e URL malformada também saem como problem details.

O `X-Correlation-Id` que o Kong coloca no request vira o id do request no Fastify (`requestIdHeader`). Sem o header, o BFF gera um UUIDv7. O id volta no header da resposta e aparece como `correlation_id` em toda linha de log do request.

Os logs são do pino que já vem no Fastify: uma linha JSON por evento, com `timestamp`, `level`, `service`, `message` e, dentro de um request, `correlation_id`. Os health checks não geram linha de request, porque o healthcheck do compose chama o ready a cada poucos segundos. Um ready que falha gera um `warn` com o estado de cada check.

## Configuração

| Variável | Default | Para quê |
|---|---|---|
| `SERVICE_NAME` | `bff` | campo `service` dos logs |
| `APP_ENV` | `production` | `local`, `staging` ou `production`; qualquer outro valor roda como `production`, a mesma regra dos serviços PHP |
| `HOST` | `0.0.0.0` | interface onde o servidor escuta |
| `PORT` | `3000` | porta HTTP |
| `LOG_LEVEL` | `info` | `fatal`, `error`, `warn`, `info`, `debug`, `trace` ou `silent` |

Valor inválido impede a subida: o processo sai com código 1 e uma linha `fatal` que lista cada problema. Variável vazia conta como não definida.

## Rodando os checks

Para não depender do Node instalado na máquina, o `make` roda tudo no mesmo `node:24-alpine` da imagem:

```bash
make check s=bff      # npm ci, lint, typecheck e testes
make logs s=bff
```

| Script | O que faz |
|---|---|
| `npm run check` | lint, typecheck e testes, na mesma ordem do CI |
| `npm test` | `node --test`, que encontra sozinho os `.ts` de `test/` |
| `npm run typecheck` | `tsc --noEmit` |
| `npm run lint` | Biome: formatação, lint e ordem dos imports |
| `npm run format` | aplica as correções do Biome |
| `npm start` | sobe o servidor; `npm run dev` faz o mesmo e reinicia a cada mudança (`node --watch`) |

A imagem sai de `docker build -f services/bff/Dockerfile -t chaos-playground/bff .`, também da raiz. Ela leva só as dependências de produção, roda como o usuário `node` e trata o `SIGTERM` do `docker stop`: para de aceitar conexões, termina os requests em andamento e sai.
