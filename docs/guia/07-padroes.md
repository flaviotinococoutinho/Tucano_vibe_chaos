# 7. Padrões e RFCs

Seguir um padrão é emprestar anos de discussão de gente que já errou antes. Quando existe uma RFC para o problema, eu uso a RFC, e quando decido não seguir, escrevo por quê. Esta é a lista, com o lugar onde cada padrão aparece.

## HTTP

| Padrão | Onde aparece | Por que |
|---|---|---|
| [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110), semântica do HTTP | `201` com `Location` ao criar o pedido, `202` ao aceitar o pagamento, `303` do rastreio por código, `409` para conflito, `422` para dado inválido, `503` com `Retry-After` | o status diz o que aconteceu antes de alguém ler o corpo |
| [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457), problem details | todo erro de todo serviço, PHP ou Node, com `correlationId` e, na validação, `errors` por campo; os problemas que o cliente precisa distinguir têm `type` próprio, explicado em [`contracts/http/problems.md`](../../contracts/http/problems.md) | um formato de erro só; o cliente decide pelo `type`, nunca lendo o texto |
| [RFC 7240](https://www.rfc-editor.org/rfc/rfc7240), `Prefer` | a estratégia de reserva do laboratório de overselling, confirmada com `Preference-Applied` | uma preferência é uma dica que o servidor pode ignorar, que é exatamente o que o laboratório faz em produção |
| [RFC 6648](https://www.rfc-editor.org/rfc/rfc6648), o fim do prefixo `X-` | header novo não leva `X-`; o `X-Correlation-Id` ficou de propósito, por ser o nome que gateways e APMs já conhecem | o sucessor padrão é o `traceparent` do W3C Trace Context, que entra com o OpenTelemetry |
| [RFC 8288](https://www.rfc-editor.org/rfc/rfc8288), web linking | as relações dos links das telas: `self`, `up`, `collection`, `item`, `next` e `prev` registradas, e as do domínio como URI | relação de extensão precisa ser URI, e cada URI aqui cai na definição dela no contrato |
| [RFC 6265](https://www.rfc-editor.org/rfc/rfc6265), cookies | o `tucano_session` com os perfis de cliente, assinado com HMAC, com `HttpOnly`, `SameSite=Lax`, `Path=/bff` e `Secure` em produção | ninguém vira outro cliente editando o cookie, e nenhum script encosta nele ([ADR 0030](../adr/0030-each-customer-sees-only-its-orders.md)) |
| [Idempotency-Key](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/), draft da IETF | todo `POST` que cria alguma coisa; a mesma chave com outro corpo é `422` | retry seguro de ponta a ponta, do clique duplo ao timeout |
| [Siren](https://github.com/kevinswiber/siren), hipermídia | todas as telas do BFF, com `application/vnd.siren+json` | o servidor manda o próximo passo, e a web só segue ([ADR 0023](../adr/0023-server-driven-ui-with-siren.md)) |
| [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0) | as APIs dos parceiros, em `contracts/http` | o contrato do PSP e das transportadoras é conferido por lint no CI |

## Dados e mensagens

| Padrão | Onde aparece | Por que |
|---|---|---|
| [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) | todo instante que sai de um serviço, em UTC | um formato só de data, sem fuso implícito |
| [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562), UUIDv7 | a identidade de pedidos, remessas, pagamentos e eventos | gerado pelo domínio e ordenado no tempo |
| [CloudEvents 1.0](https://github.com/cloudevents/spec) e o binding de Kafka | o envelope de todo evento, em modo estruturado, com `content-type: application/cloudevents+json` | um envelope só para todos os contextos, com as extensões `correlationid` e `causationid` |
| [JSON Schema 2020-12](https://json-schema.org/specification) | o `data` de cada evento em `contracts/events` e o schema das telas Siren | os testes conferem cada evento publicado e cada tela de exemplo contra o schema |
| ISO 4217 | a moeda ao lado de todo valor em centavos | `BRL`, e nunca um símbolo solto |
| Base32 de Crockford | o código de rastreio (`TX` e 13 símbolos) | sem letras que se confundem ao ditar; quem digita `O` no lugar de `0` ainda acha a encomenda |
| Divisões territoriais do IBGE | o endereço, do estado ao bairro, com a UF e o código do município | endereço brasileiro de verdade, com `KM 500` e `S/N` ([ADR 0020](../adr/0020-address-by-thoroughfare-and-divisions.md)) |

## Interface

| Padrão | Onde aparece | Por que |
|---|---|---|
| [WCAG 2.2](https://www.w3.org/TR/WCAG22/) | contraste de cada par de cores dos tokens, anel de foco visível, foco no título depois de navegar, formulário com erro ao lado do campo e resumo com links | a loja precisa funcionar com teclado e leitor de tela |
| WAI-ARIA | região `aria-live` que anuncia a mudança de status de uma tela viva, `aria-invalid` e `aria-describedby` nos campos | o que muda na tela também chega a quem não vê a tela |
| Content Security Policy | o nginx da web só deixa carregar script, estilo, imagem e fonte da própria origem | um script injetado não roda |

## Operação e processo

| Padrão | Onde aparece | Por que |
|---|---|---|
| [The Twelve-Factor App](https://12factor.net/pt_br/) | toda configuração vem do ambiente, com a unidade no nome ([ADR 0021](../adr/0021-configuration-from-the-environment.md)) | a mesma imagem em qualquer ambiente |
| [OpenFeature](https://openfeature.dev/specification/) | as feature flags de todos os serviços, servidas pelo flagd | trocar o provedor de flags não muda o código que pergunta |
| [SemVer 2.0](https://semver.org/lang/pt-BR/) | as versões do projeto, com a tag gerada a partir da `main` | o número diz se a mudança quebra alguma coisa |
| [Conventional Commits](https://www.conventionalcommits.org/pt-br/v1.0.0/) | todo commit e todo título de PR | o histórico conta a história, e as notas da versão saem dele |
| [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) | o [CHANGELOG](../../CHANGELOG.md) | o que mudou, escrito para gente |
| Git Flow | `feat/`, `fix/` e `chore/` para o `develop`; `release/` e `hotfix/` para a `main` | cada tipo de mudança tem o seu caminho, e o `pr-policy` cobra |
| Modelo C4 e Mermaid | os diagramas da [arquitetura](../architecture/README.md) | diagrama como texto, versionado junto com o código |
| ADR, no formato de Michael Nygard | [`docs/adr`](../adr/README.md) | contexto, decisão, consequências e alternativas de cada escolha |
| Casos de uso de Cockburn | [`docs/use-cases`](../use-cases/README.md) | o sistema descrito pelo objetivo de cada ator |

## Leis e regras de mercado

| Regra | Onde aparece | Por que |
|---|---|---|
| LGPD (Lei 13.709/2018) | nome, e-mail e documento vivem num `Sensitive` e só saem por `reveal()`; a leitura pública do pedido mostra o cliente mascarado; quem recebeu a encomenda nunca vai para um tópico | usar só o dado necessário, e não vazar por acidente ([ADR 0024](../adr/0024-sensitive-data-behind-a-proxy.md)) |
| PCI DSS | o cartão chega como token do PSP; nenhum serviço vê o número; uma mensagem de erro nunca repete o que recebeu | fica fora do escopo de dados de cartão, e um número mandado por engano não volta nem vai para o log |

Próximo capítulo: [como apresentar o projeto](08-como-apresentar.md).
