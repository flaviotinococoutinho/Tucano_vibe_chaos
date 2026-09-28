# web

A loja da Tucano, em React 19 e TypeScript, construída pelo Vite e servida como arquivos estáticos pelo nginx. Não escrevi telas: escrevi um intérprete de hipermídia. O BFF responde cada tela em Siren (contrato em `contracts/http/bff`), e a web desenha o que chega, segue os links e envia as ações. O fluxo mora no servidor, não aqui.

A decisão está no [ADR 0023](../../docs/adr/0023-server-driven-ui-with-siren.md).

## O intérprete, em uma frase

Um registro liga a classe de uma tela (`home`, `catalog`, `product`, `checkout`, `order`, `tracking`) a um componente React. Uma classe que o registro não conhece cai num renderizador genérico, que mostra as `properties`, os `entities`, os `links` e as `actions` do jeito que a Siren já organiza. Por isso uma tela nova do BFF funciona antes mesmo de eu escrever um componente para ela.

## Como está organizado

Cada pasta de `src/` é um módulo com uma única porta de entrada, `index.ts`, e os módulos só se enxergam por ela: `screens` importa de `hypermedia`, mas nunca abre `hypermedia/client.ts` direto.

| Módulo | O que tem |
|---|---|
| `siren/` | o vocabulário do contrato: os tipos de tela, link, ação e campo, e funções puras para ler e validar `properties` (`readMoney`, `readTone`, `readNotice`) e formatar um instante RFC 3339 para o leitor |
| `hypermedia/` | o único lugar com efeito colateral: `client.ts` fala com o `fetch`, `history.ts` fala com a History API, `prefix.ts` guarda a única URL fixa do app (`/bff/v1`) e converte entre o caminho do navegador e o endereço do BFF, e `HypermediaProvider` amarra tudo isso a um estado React (`useHypermedia`) que toda tela e todo componente usa para navegar e para enviar ações |
| `theme/` | os tokens de cor como tabela (`tone.ts`, tom vira estilo de badge) e o hook do tema claro/escuro (`useTheme`) |
| `components/` | peças de apresentação: `Link`, `ActionForm`, `Field`, `Badge`, `Notice`, `ProductCard`, `OrderLine`, `TimelineStep`, os estados de carregando, vazio e erro |
| `screens/` | o registro de telas, o `ScreenRouter` (cabeçalho, aviso, foco, o corpo trocado por baixo) e um componente por classe conhecida, mais o `GenericScreen` de reserva |

`App.tsx` só junta `HypermediaProvider`, o cabeçalho e o `ScreenRouter`. `main.tsx` monta a árvore.

## Como adicionar um componente para uma classe nova

1. O BFF já manda a tela: sem um componente, ela aparece pelo `GenericScreen`, então nada quebra enquanto eu não termino.
2. Crio `src/screens/MinhaTelaScreen.tsx`, recebendo `{ screen: SirenScreen }` e lendo o que precisa de `screen.properties` com as funções de `siren/` (`readString`, `readMoney`, `readTone`...).
3. Registro a classe em `src/screens/registry.ts`.
4. Se a tela trouxer um componente novo (uma classe de `entities` que ainda não existe), crio um em `src/components/`, do mesmo jeito: leio as propriedades com `siren/`, sigo `links` com `<Link>`, mostro `actions` com `<ActionForm>`.
5. Escrevo o teste em `test/screens/`, do jeito que os outros fazem: importo o exemplo real de `contracts/http/bff/examples` (nunca copio o JSON) e confirmo que o título e o conteúdo esperado aparecem.

Nenhum desses passos toca `hypermedia/`. Ele já sabe buscar a tela, seguir um link e enviar uma ação, porque isso não muda de tela para tela.

## Sem rota, sem URL montada à mão

O caminho do navegador e o endereço do BFF são só uma soma de prefixo: `toBffHref` põe `/bff/v1` na frente, `toBrowserPath` tira. Essa é a única URL fixa do código inteiro, em `hypermedia/prefix.ts`, e um teste (`test/hypermedia/no-hardcoded-urls.test.ts`) varre `src/` e falha se o literal `/bff/v1` aparecer em outro lugar.

Todo link é uma âncora de verdade (`<a href>`), com o caminho do navegador já no `href`, então clique do meio e abrir em nova aba funcionam sem JavaScript nenhum. Um clique simples do botão esquerdo, sem tecla modificadora, é interceptado para navegar pelo `fetch` em vez de recarregar a página. Um link de classe `external` não passa por nada disso: sai como âncora comum, `target="_blank"` e `rel="noopener"`.

Uma ação GET (`track-by-code`, `buy`) vira consulta na URL da própria ação; o `fetch` segue um 303 sozinho, e o endereço final da resposta é o que entra no histórico. Uma ação POST (`place-order`, `pay`) manda JSON e, num 201 ou 202, o corpo da resposta já é a tela seguinte: o cabeçalho `Location` só diz que endereço mostrar, sem buscar de novo. Um 422 fica na mesma tela: cada mensagem de `errors` aparece ao lado do campo (`aria-invalid`, `aria-describedby`), um resumo lista todos com link para cada campo, e o foco vai para o primeiro campo inválido.

## Tela viva

Uma tela com `"live"` na classe pede para ser buscada de novo pelo link `self`, depois de `properties.refreshAfterSeconds`. O `HypermediaProvider` cuida disso sozinho, para qualquer classe de tela, não só `order`: pausa enquanto a aba está oculta (`document.hidden`), retoma na hora quando ela volta a ficar visível, e anuncia a mudança de `statusLabel` numa região `aria-live="polite"`, sem tirar o foco de onde a pessoa está.

## Acessibilidade

- Landmarks (`header`, `main`) e um link de pular para o conteúdo, visível ao ganhar foco.
- Depois de toda navegação de verdade, o foco vai para o `h1` da tela (o título já é da tela, um só lugar cuida disso, no `ScreenRouter`); uma atualização de tela viva nunca rouba o foco.
- O anel de foco é sempre visível e sempre em duas camadas: um anel na cor da superfície, depois um na cor do texto. Assim o contraste do anel nunca depende do que está atrás do elemento focado, nem um botão laranja.
- `prefers-reduced-motion: reduce` zera a duração de toda animação e transição.
- A tela anterior fica visível enquanto a próxima carrega (uma barra de progresso no topo); o esqueleto de carregamento só aparece na primeira tela.

## Tokens e tema

As cores são as da Tucano (`docs/assets/README.md`), como propriedades customizadas em `src/styles/tokens.css`: claro por padrão, escuro por `prefers-color-scheme` ou pelo botão do cabeçalho, que grava a escolha em `localStorage` (`theme/useTheme.ts`, tudo dentro de `try/catch`, porque navegação privada e cota cheia existem).

O laranja da marca (`beak`) nunca é texto, só fundo de botão com texto `ink`, como o guia de identidade pede. No tema escuro, `teal-deep` e `coral-deep` não valem mais como texto sobre `ink` (o contraste medido cai para 2,99:1 e 3,58:1, abaixo dos 4,5:1 que o WCAG pede); o CSS troca por um teal mais claro (`#3FC7C7`, 8,34:1) e pelo `coral` comum (4,66:1). As contas e as fontes de cada par estão comentadas em `src/styles/tokens.css`.

Tom (`neutral`, `waiting`, `info`, `success`, `danger`) nunca vira cor dentro de um componente: `theme/tone.ts` é a única tabela que faz essa conta, e um badge ou um aviso só perguntam a ela.

A fonte é a Nunito, self-hosted pelo `@fontsource-variable/nunito`: nenhum request sai para uma fonte externa.

## Testes

Vitest, `@testing-library/react` e `user-event`, ambiente `jsdom`. `test/support/fixtures.ts` lê os oito exemplos reais de `contracts/http/bff/examples` do caminho do repositório, sem copiar o JSON: uma asserção lê o valor esperado do próprio fixture (o título, a mensagem de erro, o `detail` do problema), nunca reescreve o texto à mão, para não descolar quando o BFF mudar um exemplo.

| Teste | O que cobre |
|---|---|
| `screens/registry.test.ts` | a classe certa pega o componente certo, e uma classe desconhecida cai no genérico |
| `screens/examples.test.tsx` | as oito telas de exemplo renderizam, cada uma com seu título como `h1` |
| `components/action-form.test.tsx` | um formulário nasce dos campos de uma ação, com rótulo, obrigatoriedade e opções de `select` |
| `screens/place-order.test.tsx` | o POST de `place-order` leva os campos ocultos e os digitados, segue o `Location` de um 201, e um 422 mostra cada mensagem, o resumo e o foco no primeiro campo inválido |
| `hypermedia/live.test.tsx` | uma tela viva busca de novo o `self` depois do intervalo, e pausa e retoma com a visibilidade da aba |
| `hypermedia/prefix.test.ts` | a ida e volta entre caminho do navegador e endereço do BFF |
| `components/link.test.tsx` | clique simples navega pelo `fetch`; clique com Ctrl não é interceptado; link `external` sai como âncora comum |
| `hypermedia/no-hardcoded-urls.test.ts` | nenhum arquivo além de `hypermedia/prefix.ts` tem o literal `/bff/v1` |

## Rodando os checks

```bash
make check s=web      # npm ci e npm run check
make dev s=web
```

| Script | O que faz |
|---|---|
| `npm run check` | lint, typecheck, testes e build, na mesma ordem do CI |
| `npm test` | `vitest run` |
| `npm run typecheck` | `tsc --noEmit` |
| `npm run lint` | Biome: formatação, lint e ordem dos imports |
| `npm run format` | aplica as correções do Biome |
| `npm run dev` | Vite em modo de desenvolvimento, com proxy de `/bff` para `http://localhost:8000` (o Kong local) |
| `npm run build` | build de produção em `dist/` |
| `npm run preview` | serve o `dist/` para conferir o build antes de publicar |

Ao contrário do `bff` e do `partners-sim`, aqui existe uma etapa de build de verdade: o Vite empacota TypeScript e JSX em arquivos estáticos, porque é isso que o navegador precisa. Por isso o `tsconfig.json` usa `moduleResolution: bundler` e permite importar com a extensão `.ts`/`.tsx` (`allowImportingTsExtensions`), em vez do jeito sem build do `bff`. O resto da disciplina de tipos é a mesma: `strict`, `noUncheckedIndexedAccess`, sem `enum` nem `namespace`.

A imagem sai de `docker build -f services/web/Dockerfile -t chaos-playground/web .`, da raiz do repositório. Ela tem duas etapas: `node:24-alpine` builda o `dist/`, e só ele atravessa para a imagem final, `nginx:stable-alpine`. O nginx serve os arquivos com cabeçalhos de segurança (`Content-Security-Policy` restrito ao que o app usa, `X-Content-Type-Options`, `Referrer-Policy`), cache de um ano para o que o Vite já marca com hash no nome (`assets/`) e revalidação para o resto (o `index.html`, os ícones, o manifesto), com fallback para `index.html` em qualquer caminho que não seja um arquivo, porque quem decide o que mostrar ali é o React, não o nginx.
