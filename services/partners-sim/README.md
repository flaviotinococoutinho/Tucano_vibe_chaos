# partners-sim

Simulador do mundo de fora da Tucano, em Node 24 com Fastify 5 e TypeScript executado direto pelo Node. Ele faz o papel do PayFake (o PSP), das transportadoras parceiras e do app dos entregadores, para os serviços terem com quem conversar e para eu poder estragar essa conversa de propósito. Por enquanto só o PayFake existe. As transportadoras e os entregadores vêm depois.

## PayFake

O PayFake é o PSP da Tucano. Ele fala a própria língua (charge, refund, event), e o commerce traduz isso para `Payment` na borda do contexto de Payments, a anticorruption layer do [context map](../../docs/architecture/context-map.md). O contrato completo, com operações, webhooks e assinatura, está em [`contracts/http/payfake.openapi.yaml`](../../contracts/http/payfake.openapi.yaml).

| Rota | O que faz |
|---|---|
| `POST /payfake/v1/charges` | cria uma cobrança, que nasce `processing` |
| `GET /payfake/v1/charges?reference=` | acha a cobrança pela referência do lojista, que é o que a conciliação lê: o id da cobrança pode ter se perdido junto com a resposta |
| `GET /payfake/v1/charges/{id}` | mostra a cobrança como ela está agora |
| `POST /payfake/v1/charges/{id}/refunds` | estorna uma cobrança `succeeded`, sempre pelo valor total |
| `GET`, `PUT` e `DELETE /_chaos/payfake` | os controles de caos |

Uma cobrança, do host:

```bash
curl -s localhost:4000/payfake/v1/charges \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: 01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b' \
  -d '{"amount": {"value": 18990, "currency": "BRL"}, "cardToken": "tok_visa", "reference": "01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b"}'
```

A resposta é `201` com a cobrança em `processing`. O resultado não vem na resposta: depois de um tempo de processamento sorteado entre 300 e 1500 ms, a cobrança vira `succeeded` ou `failed`, e o PayFake avisa o commerce por webhook. É o desenho de um PSP de verdade, e é ele que obriga o commerce a lidar com webhook atrasado, duplicado ou perdido.

Os ids têm prefixo e 26 caracteres (`ch_01M3HBNT8TE1R8SV13STYN9CXP`): um UUIDv7 em Base32 de Crockford, o mesmo alfabeto do código de rastreio, então ordenam pela criação.

### Ciclo de vida

A cobrança tem um estado só, uma discriminated union, e cada estado carrega só o que é dele:

| Estado | O que carrega além dos dados da cobrança |
|---|---|
| `processing` | nada |
| `succeeded` | nada |
| `failed` | `failureCode`: `card_declined` ou `insufficient_funds` |
| `refunded` | o `refund`, que começa `processing` e depois vira `succeeded` |

Todas as transições ficam numa função só, `transition()` em `src/payfake/charge.ts`. O que ela não prevê é recusado com `TransitionNotAllowed`, que a API responde com `409`. Estornar uma cobrança que ainda está processando, que falhou ou que já foi estornada cai nesse caso. Estorno parcial responde `422`.

### Cartões mágicos

| `cardToken` | Resultado |
|---|---|
| `tok_decline` | `failed` com `card_declined` |
| `tok_insufficient` | `failed` com `insufficient_funds` |
| qualquer outro `tok_*` | `succeeded`, a não ser que o `declineRate` do caos diga o contrário |

### Idempotência

Todo `POST` exige `Idempotency-Key`. Sem ela, a resposta é `400`. O commerce manda o id do pagamento como chave, então um pagamento vira no máximo uma cobrança.

- Mesma chave e mesmo corpo: volta a primeira resposta, igual, com o header `Idempotent-Replayed: true`. Vale mesmo depois de a cobrança mudar de estado, porque o que volta é o que foi respondido na época.
- Mesma chave e outro corpo: `422`. A ordem dos campos não conta, porque comparo o SHA-256 do JSON com as chaves ordenadas.
- Só resposta de sucesso fica guardada. Se o request falhou, dá para repetir com a mesma chave.

As chaves valem por 24 horas. Cobrança e estorno têm espaços de chave separados, e no estorno o id da cobrança entra na comparação.

Isso ajuda a conciliação do commerce: se ele perdeu a resposta de uma cobrança num timeout, repetir o `POST` com a mesma chave devolve a cobrança original, com o id. Não precisa de busca por referência.

### Webhooks

Quando uma cobrança ou um estorno termina, o PayFake manda um `POST` para `PAYFAKE_WEBHOOK_URL`, que por padrão é o commerce passando pelo Kong:

```json
{
  "id": "evt_01M3HBNT8XEN7BHPFP3E6P118G",
  "type": "charge.succeeded",
  "createdAt": "2026-09-27T12:00:00.900Z",
  "data": {
    "chargeId": "ch_01M3HBNT8TE1R8SV13STYN9CXP",
    "reference": "01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b",
    "amount": { "value": 18990, "currency": "BRL" }
  }
}
```

Os tipos são `charge.succeeded`, `charge.failed` (com `failureCode` em `data`) e `refund.succeeded`. O webhook leva o `X-Correlation-Id` do request que criou a cobrança, então dá para seguir um pagamento do checkout até o webhook nos logs.

A entrega é at-least-once. Resposta fora de 2xx (redirect também), erro de rede ou 5 segundos sem resposta contam como falha, e o PayFake tenta de novo depois de 1, 2, 4, 8 e 16 segundos: seis tentativas em 31 segundos. Depois disso ele desiste, loga um `error` e o caso sobra para a conciliação. Retry e duplicata levam o mesmo evento, com o mesmo `id`, e é por esse id que o commerce deduplica na inbox.

### Assinatura

Todo webhook vem com este header:

```text
PayFake-Signature: t=1790510400,v1=0605457e458dd9fea675921d4df5754d4330d115baa8c60beb64f86dc29f4061
```

O `v1` é o HMAC-SHA256 em hex, com o segredo compartilhado (`PAYFAKE_WEBHOOK_SECRET`), de `<t>.<corpo cru>`: o timestamp em segundos, um ponto e o corpo exatamente como chegou. Para verificar:

1. separar o header nas vírgulas e ler o `t` e todos os `v1`;
2. recusar o webhook se o `t` estiver a mais de 300 segundos do relógio de quem recebe;
3. calcular o HMAC de `<t>.<corpo cru>` e comparar com cada `v1` em tempo constante (`hash_equals` no PHP). Basta um bater.

O timestamp entra no HMAC para ninguém reaproveitar um webhook capturado com um `t` novo. O corpo tem que ser o cru: decodificar o JSON e codificar de novo muda os bytes e quebra a assinatura. Pode chegar mais de um `v1`, o que permite trocar o segredo sem parar nada. Cada tentativa é assinada de novo, com um `t` novo, então um retry tardio continua dentro da tolerância.

Os helpers `sign()` e `verify()` estão em `src/payfake/signature.ts`. O teste deles tem um vetor calculado com o `openssl`, para o commerce repetir no PHP: segredo `whsec_local_payfake`, `t=1790510400` e o corpo `{"id":"evt_01J8Z5W3Q4X9M2N7B8C6D5E4F3","type":"charge.succeeded"}` dão o `v1` do exemplo acima.

### Controles de caos

Os controles mudam com o serviço rodando e ficam na memória:

| Controle | O que faz |
|---|---|
| `latencyMs.min` e `latencyMs.max` | atraso sorteado entre os dois antes de responder criação, leitura e estorno |
| `errorRate` | fração das criações de cobrança que respondem `500`. A falha vem antes de a cobrança existir, então repetir com a mesma chave é seguro. Replay nunca falha assim |
| `timeoutRate` | fração das respostas de criação de cobrança, replays inclusive, que ficam presas até o cliente desistir, por no máximo 30 segundos. A cobrança existe, e a nova tentativa com a mesma chave recebe a resposta original |
| `declineRate` | fração das cobranças com cartão comum que falham com `card_declined` |
| `webhooks.dropRate` | fração dos webhooks que nunca saem. Só a conciliação encontra essas cobranças |
| `webhooks.duplicateRate` | fração dos webhooks mandados duas vezes, com o mesmo `id` |
| `webhooks.delayMs` | espera antes da primeira tentativa de todo webhook |

O `PUT` descreve o experimento inteiro: o controle que fica de fora volta para o valor calmo. Taxa fora de 0 a 1, `max` menor que `min`, campo desconhecido ou tipo errado respondem `422` com o campo que falhou, e nada muda.

```bash
# PSP lento e instável: 200 a 800 ms de latência, 10% de erro 500 e 20% de timeout
curl -s -X PUT localhost:4000/_chaos/payfake -H 'Content-Type: application/json' \
  -d '{"latencyMs": {"min": 200, "max": 800}, "errorRate": 0.1, "timeoutRate": 0.2}'

# webhooks ruins: metade duplicada, 10% perdida e todos com 5 s de atraso
curl -s -X PUT localhost:4000/_chaos/payfake -H 'Content-Type: application/json' \
  -d '{"webhooks": {"duplicateRate": 0.5, "dropRate": 0.1, "delayMs": 5000}}'

curl -s localhost:4000/_chaos/payfake              # o que está valendo
curl -s -X DELETE localhost:4000/_chaos/payfake    # tudo calmo de novo
```

Cada decisão do caos vira uma linha `info` no log, com o campo `chaos` e o id da cobrança. No `500`, que vem antes de a cobrança existir, a linha leva a `reference`. Para seguir um experimento:

```bash
docker compose logs partners-sim | grep '"chaos"'
```

Esses controles são do PayFake e não passam pelo flagd: são o mundo de fora se comportando mal. Para degradar a rede entre o commerce e o PSP, o caminho continua sendo o proxy `payfake` do Toxiproxy.

### Por que o estado fica na memória

Da rede `edge` o simulador não enxerga banco nenhum, e isso é de propósito: ele faz o papel de uma empresa de fora. Um processo Node de longa duração guarda cobranças, chaves e controles em `Map` sem esforço, e os timers do event loop cuidam da liquidação e dos retries dos webhooks.

O preço está aceito: um restart apaga tudo, inclusive os webhooks que ainda iam ser tentados. Para o laboratório isso é mais um cenário, o PSP que perdeu o estado, e é o tipo de problema que a conciliação do commerce existe para resolver.

Para caber no limite de memória do container, cobranças e chaves somem depois de 24 horas, e o simulador guarda no máximo as 20 mil mais recentes, cerca de 10 MB de heap. No shutdown, o que estava esperando é cancelado na hora, e uma resposta presa pelo `timeoutRate` sai com `Connection: close`. Sem isso, o Fastify esperaria o keep-alive da conexão, de 72 segundos, e o `docker stop` mataria o processo antes.

Tempo e acaso entram injetados (`Clock` e `Random`), então os testes controlam o relógio e o sorteio sem esperar nada.

## O que vem depois

- **Transportadoras**: criação de envio e eventos de rastreio para o logistics, cada transportadora com o próprio formato, que o logistics traduz na borda (ACL).
- **Entregadores**: aparelhos simulados que mandam posição de GPS ao tracking por WebSocket e confirmam as entregas, com entregas malsucedidas entre os controles de caos.

## Onde ele fica na rede

O `partners-sim` fica só na rede `edge`, que faz o papel da internet. Ele não alcança banco nem serviço interno e fala com a Tucano só pelo Kong, como um parceiro de verdade. No outro sentido, os serviços chegam até ele pelo Toxiproxy: `toxiproxy:14001` para o PayFake e `toxiproxy:14002` para as transportadoras. São dois proxies para o mesmo container, então dá para degradar o PSP sem mexer nas transportadoras. Do host, o compose publica a porta só em `127.0.0.1:4000`, que é por onde passam os `curl` deste README. Os detalhes estão na [topologia local](../../docs/architecture/deployment.md).

## Por que Node aqui

A decisão está no [ADR 0014](../../docs/adr/0014-node-for-bff-and-partners.md). Simular parceiro é quase só I/O: receber a chamada, esperar, responder e disparar o webhook com atraso. Um processo Node de longa duração faz isso com timers e o event loop, e guarda o estado na memória, já que da rede `edge` ele não enxerga banco nenhum.

## TypeScript sem build

Segue as mesmas regras do bff: o Node 24 executa os `.ts` direto (type stripping), sem etapa de build, e o `tsc` só checa tipos. As restrições de sintaxe que isso traz estão no [README do bff](../bff/README.md#typescript-sem-build).

## Como está organizado

| Caminho | O que tem |
|---|---|
| `src/server.ts` | ponto de entrada: lê a configuração, abre a porta e faz o graceful shutdown no `SIGTERM` e no `SIGINT` |
| `src/app.ts` | `buildApp()`, que monta o Fastify sem abrir porta e recebe o `Clock` e o `Random` |
| `src/config.ts` | variáveis de ambiente, com defaults e validação na subida |
| `src/clock.ts`, `src/chance.ts` | o relógio e o sorteio, injetados em tudo que espera ou decide ao acaso |
| `src/expiring-map.ts` | o `Map` com validade e limite de tamanho onde fica o estado |
| `src/payfake/` | o PayFake: ciclo de vida, idempotência, caos, webhooks, assinatura e as rotas |
| `src/platform/` | a cola com o Fastify: correlation id, logs, problem details, `DomainError` e health checks |
| `test/` | testes com `node:test`, pelo `app.inject()` e, nos webhooks e timeouts, com servidor HTTP de verdade |
| `test/support/` | relógio instantâneo, receptor de webhooks e atalhos dos testes |

O layout é o mesmo do [`bff`](../bff/README.md), e a pasta `src/platform/` é idêntica nos dois de propósito: a CI compara as cópias ([ADR 0016](../../docs/adr/0016-copied-node-platform.md)). Cada parceiro simulado entra como uma pasta em `src/`, com suas rotas registradas como plugin do Fastify.

## Health, erros e logs

| Rota | O que faz |
|---|---|
| `GET /health/live` | o processo está de pé |
| `GET /health/ready` | as dependências respondem, com a latência de cada uma |

Não tem rota no Kong: quem chama o simulador são os serviços, pelo Toxiproxy. Ele não depende de ninguém para atender, então o ready responde `{"status":"up","checks":{}}`. O destino dos webhooks fica de fora de propósito: um PSP não sai do ar porque o lojista caiu. Cada dependência nova entra como um check `{ name, check }` na lista que o `buildApp()` recebe. Os checks rodam em paralelo, e o ready responde 503 quando algum falha.

Todo erro sai como `application/problem+json` (RFC 9457) com os mesmos campos do commerce: `type`, `title`, `status`, `detail`, `instance` e `correlationId`. Falha de validação do JSON Schema da rota vira 422 com `errors`, a lista de mensagens por campo. Erro de domínio (`DomainError`) vira status pela categoria, como no PHP. Erro inesperado responde 500 com uma mensagem genérica, e o stack vai para o log junto com o correlation id. Rota desconhecida e URL malformada também saem como problem details.

A validação é estrita como a de um PSP: campo desconhecido e tipo errado respondem 422, em vez de o Ajv descartar o campo ou converter o tipo em silêncio.

O `X-Correlation-Id` que chega no request vira o id do request no Fastify (`requestIdHeader`). Sem o header, o simulador gera um UUIDv7. O id volta no header da resposta e aparece como `correlation_id` em toda linha de log do request, inclusive nas da liquidação e dos webhooks que ele causou.

Os logs são do pino que já vem no Fastify: uma linha JSON por evento, com `timestamp`, `level`, `service`, `message` e, dentro de um request, `correlation_id`. Os health checks não geram linha de request, porque o healthcheck do compose chama o ready a cada poucos segundos. Um ready que falha gera um `warn` com o estado de cada check.

## Configuração

| Variável | Default | Para quê |
|---|---|---|
| `SERVICE_NAME` | `partners-sim` | campo `service` dos logs |
| `APP_ENV` | `production` | `local`, `staging` ou `production`; qualquer outro valor roda como `production`, a mesma regra dos serviços PHP |
| `HOST` | `0.0.0.0` | interface onde o servidor escuta |
| `PORT` | `4000` | porta HTTP |
| `LOG_LEVEL` | `info` | `fatal`, `error`, `warn`, `info`, `debug`, `trace` ou `silent` |
| `PAYFAKE_WEBHOOK_URL` | `http://kong:8000/api/commerce/v1/webhooks/payfake` | para onde vão os webhooks, `http` ou `https` |
| `PAYFAKE_WEBHOOK_SECRET` | `whsec_local_payfake` | segredo do HMAC da assinatura, o mesmo que o commerce usa para verificar |
| `PAYFAKE_PROCESSING_MIN_MS` | `300` | menor tempo de processamento de uma cobrança ou de um estorno |
| `PAYFAKE_PROCESSING_MAX_MS` | `1500` | maior tempo de processamento; não pode ser menor que o mínimo, e os dois vão até 600000 |

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
| `npm test` | `node --test` com o glob `test/**/*.test.ts`, para os arquivos de `test/support/` não rodarem como teste |
| `npm run typecheck` | `tsc --noEmit` |
| `npm run lint` | Biome: formatação, lint e ordem dos imports |
| `npm run format` | aplica as correções do Biome |
| `npm start` | sobe o servidor; `npm run dev` faz o mesmo e reinicia a cada mudança (`node --watch`) |

Os testes não esperam o relógio. A maioria usa um `Clock` instantâneo que registra cada espera, então o backoff de 1, 2, 4, 8 e 16 segundos é conferido em milissegundos. O teste do tempo de processamento usa o relógio de verdade com os mock timers do `node:test`. Os webhooks vão para um servidor HTTP local de verdade, que responde o que cada teste manda, e o timeout usa uma conexão real que o cliente abandona.

A imagem sai de `docker build -f services/partners-sim/Dockerfile -t chaos-playground/partners-sim .`, também da raiz. Ela leva só as dependências de produção, roda como o usuário `node` e trata o `SIGTERM` do `docker stop`: para de aceitar conexões, cancela liquidações e retries pendentes, termina os requests em andamento e sai.
