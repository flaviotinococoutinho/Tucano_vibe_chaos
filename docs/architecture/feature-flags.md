# Feature flags

Uso feature flags para três coisas: ligar funcionalidades de produto por ambiente, trocar comportamento operacional sem deploy e injetar falhas nos experimentos de caos. Todas as flags são privadas: ficam no servidor, versionadas no repositório, e o navegador nunca conversa com o servidor de flags.

## OpenFeature + flagd

- **OpenFeature** é a API padrão da CNCF para avaliar flags, com SDKs para PHP, Node e navegador. O código depende só da API, então dá para trocar o fornecedor sem mexer nos serviços. É a mesma ideia do OpenTelemetry, aplicada a flags.
- **flagd** é o servidor de referência do OpenFeature: lê as definições de um arquivo JSON, avalia regras de targeting (JsonLogic, rollout percentual) e responde por gRPC, HTTP e OFREP.

As definições ficam em `infra/flags/environments/`, um arquivo por tipo de ambiente:

| Arquivo | Para quê |
|---|---|
| `local.flagd.json` | desenvolvimento: tudo ligado, laboratórios e caos disponíveis |
| `staging.flagd.json` | caos disponível mas desligado; rollout de 50% no frete expresso |
| `production.flagd.json` | sem flags de caos nem de laboratório; rollout de 10% e dogfooding |

O ambiente sai de `APP_ENV`: `APP_ENV=production make up` sobe a stack com as flags de produção.

## Tipos de flag

Sigo a classificação de Pete Hodgson (artigo "Feature Toggles", no site do Martin Fowler), porque ela diz quanto tempo cada flag deve viver. A última linha é uma categoria que acrescentei para o laboratório:

| Tipo | Exemplos | Vida útil |
|---|---|---|
| release toggle | `checkout.express-shipping`, `catalog.stock-badge` | até a funcionalidade estabilizar, depois sai do código |
| experiment toggle | rollout percentual do frete expresso | enquanto o experimento durar |
| ops toggle | `logistics.own-fleet-dispatch`, `inventory.reservation-strategy` | longa; é uma chave operacional |
| permissioning toggle | `tracking.live-map` em produção, liberada só para e-mails internos (dogfooding) | enquanto houver público restrito |
| chaos toggle | `chaos.*` | só existe fora de produção |

## Catálogo

| Flag | Tipo de valor | local | staging | production |
|---|---|---|---|---|
| `labs.enabled` | boolean | ligada | ligada | não existe |
| `chaos.enabled` | boolean | ligada | desligada | não existe |
| `chaos.commerce.payment-gateway-latency-ms` | inteiro | 0 | 0 | não existe |
| `chaos.commerce.outbox-relay-paused` | boolean | desligada | desligada | não existe |
| `chaos.commerce.order-projector-paused` | boolean | desligada | desligada | não existe |
| `chaos.logistics.label-failure-rate` | decimal | 0 | 0 | não existe |
| `chaos.logistics.outbox-relay-paused` | boolean | desligada | desligada | não existe |
| `chaos.tracking.gps-drop-rate` | decimal | 0 | 0 | não existe |
| `inventory.reservation-strategy` | string | `atomic` (5 variantes) | `atomic` (2 variantes) | `atomic` (única variante) |
| `logistics.own-fleet-dispatch` | boolean | ligada | ligada | ligada |
| `checkout.express-shipping` | boolean | ligada | 50% dos clientes | 10% dos clientes |
| `catalog.stock-badge` | boolean | ligada | ligada | ligada |
| `tracking.live-map` | boolean | ligada | ligada | só e-mails `@tucano.example` |

As chaves seguem o formato `<área>.<nome>` em kebab-case. O rollout percentual usa o `targetingKey` do contexto, que é o id do cliente, então o mesmo cliente sempre cai no mesmo grupo.

## Regras de segurança

1. **Defaults seguros no código.** Toda avaliação passa um valor padrão, e o padrão é sempre o comportamento conservador (caos desligado, estratégia `atomic`). Se o flagd cair ou a flag não existir, o sistema segue funcionando.
2. **Caos não existe em produção.** As flags `chaos.*` e `labs.*` não aparecem no arquivo de produção, e o código ainda confere `APP_ENV`: com `production`, qualquer flag de caos é tratada como desligada.
3. **Flags privadas.** O flagd fica só na rede `backend`, sem rota no Kong. A web recebe apenas o valor já resolvido das flags de interface, através do BFF.

## Avaliação em cada runtime

| Runtime | Provider | Como avalia |
|---|---|---|
| PHP (FPM e Swoole) | `open-feature/flagd-provider` | chamada HTTP ao flagd (porta 8013) a cada avaliação, com cache curto em memória |
| Node (BFF) | `@openfeature/flagd-provider` em modo in-process | sincroniza as definições pela porta 8015 e avalia localmente, sem ida à rede |

A diferença é intencional e rende conversa de entrevista. No PHP-FPM cada request começa do zero, então não há onde manter uma cópia viva das regras; o custo é uma chamada de rede por avaliação, que o cache resolve. No Node o processo é longo, e a avaliação in-process fica em microssegundos.

O `partners-sim` não lê flags. Ele faz o papel de empresas de fora, que não conhecem as flags da Tucano, e fica só na rede `edge`, sem acesso ao flagd. O comportamento dele muda pela própria API de caos.

## No código PHP

Os serviços PHP usam o pacote `tucano/feature-flags` (`packages/php/feature-flags`). O código de negócio só enxerga a interface `FeatureFlags`, e a montagem é uma cadeia de decorators:

```text
ProductionGuard -> CachedFlags -> OpenFeatureFlags -> flagd
```

- `ProductionGuard` devolve o fallback para `chaos.*` e `labs.*` em produção, sem consultar o servidor.
- `CachedFlags` guarda cada avaliação por 2 segundos, em APCu no PHP-FPM e na memória do processo em CLI e Swoole.
- `OpenFeatureFlags` traduz a chamada para o cliente OpenFeature, que fala HTTP com o flagd com timeout de 300 ms.

Ambiente desconhecido é tratado como produção (`Environment::fromName('testing')` devolve `Production`): na dúvida, o default mais restritivo.

## Overrides em runtime e uma pegadinha do flagd

O painel de caos precisa ligar e desligar flags na hora, sem deploy. A primeira ideia foi carregar duas fontes no flagd (o arquivo do ambiente e um arquivo de overrides por cima), já que o flagd mescla fontes e a última vence. Testando, apareceu um comportamento do flagd v0.17.0: quando uma chave sai da fonte de maior prioridade, a flag some do servidor, mesmo existindo na fonte base, e passa a responder `FLAG_NOT_FOUND` até o flagd reiniciar.

Por isso o desenho ficou com **uma fonte só**:

1. no `make up`, o job `flags-init` copia `infra/flags/environments/<APP_ENV>.flagd.json` para o volume `flag-runtime`;
2. o flagd observa só essa cópia (`/flags/runtime/flags.flagd.json`) e recarrega a cada escrita;
3. o painel de caos edita a flag dentro da cópia; para resetar, regrava a definição original daquela flag, sem nunca remover chaves.

Os overrides são efêmeros: o próximo `make up` volta ao que está versionado. Para experimentos de caos é o comportamento que eu quero. Vale o mesmo para qualquer `docker compose up` que suba o flagd como dependência: o `flags-init` roda de novo e recopia o arquivo. Para recriar um worker no meio de um experimento, use `docker compose up -d --no-deps <serviço>`.

Da linha de comando, sem o painel:

```bash
make flag key=chaos.logistics.label-failure-rate variant=most   # serve outra variante, na hora
make flag-reset key=chaos.logistics.label-failure-rate          # volta ao que está no repositório
```

O `make flag` só aceita variantes que a flag já tem, e lista as válidas quando recebe outra.

### O laboratório de consistência

A flag `chaos.commerce.order-projector-paused` mostra na tela o que o [ADR 0012](../adr/0012-acid-writes-base-reads.md) decidiu: o pedido é lido forte, no PostgreSQL, e a lista "Meus pedidos" é lida eventual, no MongoDB. Com ela ligada, o projetor da lista sai do seu consumer group entre duas mensagens, com tudo confirmado:

```bash
make flag key=chaos.commerce.order-projector-paused variant=on
# compre alguma coisa: a tela do pedido abre na hora, e "Meus pedidos" não o mostra
make flag-reset key=chaos.commerce.order-projector-paused
# o projetor volta dos offsets confirmados, e o pedido aparece na lista em uns 2 s
```

O worker lê a flag antes de cada poll, com o cache de 2 s. Com o flagd fora, ela vale desligada, como toda flag de caos.

## Testando na mão

```bash
make flags key=customer-42
curl -s -X POST localhost:8016/ofrep/v1/evaluate/flags/checkout.express-shipping \
  -H 'Content-Type: application/json' \
  -d '{"context": {"targetingKey": "customer-42"}}'
APP_ENV=production make up && make flags
```
