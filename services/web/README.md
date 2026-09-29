# web

A loja da Tucano, em React 19 e TypeScript, construída pelo Vite e servida como arquivos estáticos pelo nginx. Não escrevi telas: escrevi um intérprete de hipermídia. O BFF responde cada tela em Siren (contrato em `contracts/http/bff`), e a web desenha o que chega, segue os links e envia as ações. O fluxo mora no servidor, não aqui.

A decisão está no [ADR 0023](../../docs/adr/0023-server-driven-ui-with-siren.md).

## O intérprete, em uma frase

Um registro liga a classe de uma tela (`home`, `catalog`, `product`, `checkout`, `orders`, `order`, `profiles`, `tracking`) a um componente React. Uma classe que o registro não conhece cai num renderizador genérico, que mostra as `properties`, os `entities`, os `links` e as `actions` do jeito que a Siren já organiza. Por isso uma tela nova do BFF funciona antes mesmo de eu escrever um componente para ela.

## Como está organizado

Cada pasta de `src/` é um módulo com uma única porta de entrada, `index.ts`, e os módulos só se enxergam por ela: `screens` importa de `hypermedia`, mas nunca abre `hypermedia/client.ts` direto.

| Módulo | O que tem |
|---|---|
| `siren/` | o vocabulário do contrato: os tipos de tela, link, ação e campo, as relações do domínio (`REL`), e funções puras para ler e validar `properties` (`readMoney`, `readTone`, `readNotice`, `readProgress`, `readShopper`) e formatar um instante RFC 3339 para o leitor |
| `hypermedia/` | o único lugar com efeito colateral: `client.ts` fala com o `fetch`, `history.ts` fala com a History API, `prefix.ts` guarda a única URL fixa do app (`/bff/v1`) e converte entre o caminho do navegador e o endereço do BFF, e `HypermediaProvider` amarra tudo isso a um estado React (`useHypermedia`) que toda tela e todo componente usa para navegar e para enviar ações |
| `theme/` | os tokens de cor como tabela (`tone.ts`, tom vira estilo de badge; `avatar.ts`, o id do perfil vira a cor do avatar) e o hook do tema claro/escuro (`useTheme`) |
| `components/` | peças de apresentação: `Header`, `Link`, `ActionForm`, `Field`, `Badge`, `Notice`, `ProductCard`, `OrderCard`, `OrderLine`, `OrderProgress`, `ProgressDots`, `ProfileCard`, `Avatar`, `TimelineStep`, `LiveDelivery`, os estados de carregando, vazio e erro |
| `screens/` | o registro de telas, o `ScreenRouter` (cabeçalho, aviso, foco, o corpo trocado por baixo) e um componente por classe conhecida, mais o `GenericScreen` de reserva |

`App.tsx` só junta `HypermediaProvider`, o cabeçalho e o `ScreenRouter`. `main.tsx` monta a árvore.

## O cabeçalho vem da tela

Toda tela do BFF traz um componente `navigation`: quem está comprando e os links do cabeçalho. O `Header` desenha a partir da tela que está na página, então não existe menu escrito na web: "Catálogo", "Meus pedidos" e o chip do perfil (o avatar com a inicial e o nome, ou "Entrar" quando o navegador ainda não tem sessão) são os links e os títulos que chegaram. Um problema não tem navegação, e o cabeçalho fica só com a marca e o botão do tema.

O link da seção onde a pessoa está leva `aria-current`, comparando só endereços que o servidor deu: `page` na própria página (a lista de pedidos) e `true` numa página dentro da seção (um pedido, sob "Meus pedidos"), como o WAI-ARIA pede. O destaque é um fundo e um sublinhado, nunca só cor. No celular, a navegação quebra para baixo da marca, sem rolagem lateral.

## A história do pedido

A tela do pedido começa pelo cartão de situação (o badge, a frase da loja sobre onde o pedido está e os dados: quando foi feito, total, rastreio e o prazo para pagar), segue pelos marcos, pelo entregador ao vivo quando o BFF oferece o `rel-live`, pelo pagamento enquanto ele existe, pelo "Histórico" e termina nos itens e nos links. O caminho de volta, "Meus pedidos", é o link `collection`, que o `ScreenRouter` já desenha acima do título.

Os marcos são uma lista ordenada: na horizontal em tela larga, na vertical no celular. Cada estado tem a sua forma (feito com um check, o atual com um ponto dentro de um anel e `aria-current="step"`, o próximo vazado, o interrompido com um X) e as suas palavras para o leitor de tela, então nenhum depende só de cor; a linha entre dois marcos é cheia quando o pedido já passou por ela e tracejada quando ainda falta. Na lista de pedidos, cada cartão resume os marcos em pontos com o badge ao lado (e, num pedido cancelado, o marco onde ele parou, que diz mais que o badge), e o título do cartão é o único link: o cartão inteiro recebe o clique, mas o leitor de tela ouve um link curto por pedido, e não o cartão lido de ponta a ponta.

Os avatares dos perfis têm a cor tirada do id (FNV-1a sobre o texto do id): o mesmo perfil tem a mesma cor no cabeçalho e na tela de perfis, sem a web guardar nada. As cores são pares da paleta que passam de 4,5:1 nos dois temas.

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

Uma ação GET (`track-by-code`, `buy`) vira consulta na URL da própria ação; o `fetch` segue um 303 sozinho, e o endereço final da resposta é o que entra no histórico. Uma ação POST (`place-order`, `pay`) manda JSON e, num 201 ou 202, o corpo da resposta já é a tela seguinte: o cabeçalho `Location` só diz que endereço mostrar, sem buscar de novo. As ações de perfil (`use-profile`, `create-profile`) respondem 303, e o `fetch` também segue esse sozinho, com um GET: o que chega já é a lista de pedidos de quem ficou comprando. Um 422 fica na mesma tela: cada mensagem de `errors` aparece ao lado do campo (`aria-invalid`, `aria-describedby`), um resumo lista todos com link para cada campo, e o foco vai para o primeiro campo inválido. Uma mensagem sobre um campo escondido (o perfil que um botão escolheu) aparece no resumo como frase, sem nome de campo e sem link, porque não há o que corrigir ali.

O formulário desliga a validação do navegador (`novalidate`). Ela pararia no primeiro campo errado, num balão e com as palavras do navegador; o BFF responde todos os campos de uma vez, em português, ao lado de cada um. É o que o GOV.UK Design System recomenda pelo mesmo motivo. `required`, `pattern`, `inputmode` e `autocomplete` continuam lá: trazem o teclado certo no celular, o preenchimento automático e o que a tecnologia assistiva anuncia.

Toda tela que traz um link `collection` ou `up` ganha o caminho de volta acima do título ("Voltar ao catálogo", "Início"), desenhado pelo `ScreenRouter` para todas de uma vez. A coleção vem antes do início, porque é o passo anterior de verdade.

## Tela viva

Uma tela com `"live"` na classe pede para ser buscada de novo pelo link `self`, depois de `properties.refreshAfterSeconds`. O `HypermediaProvider` cuida disso sozinho, para qualquer classe de tela, não só `order`: pausa enquanto a aba está oculta (`document.hidden`), retoma na hora quando ela volta a ficar visível, e anuncia a mudança de `statusLabel` numa região `aria-live="polite"`, sem tirar o foco de onde a pessoa está.

### O entregador ao vivo

Quando a tela de rastreio traz um link `rel-live`, o que o BFF só faz enquanto uma encomenda da frota própria está a caminho da porta, o `LiveDelivery` abre o WebSocket do tracking nesse endereço, resolvido contra a origem da página: `ws:` numa página `http:`, `wss:` numa `https:`, e nenhuma URL escrita no código. O protocolo está em [`contracts/tracking`](../../contracts/tracking/README.md).

- Cada posição move o ponto num mapa desenhado em SVG, com o rastro recente e a distância até a porta ("O entregador está a 1,2 km"). Não há mapa de fundo: nenhum tile de fora passaria pela CSP, e o que importa é a distância e o movimento.
- Sem posição nova há mais de 30 s, o cartão diz há quanto tempo não há sinal. O `ended` mostra o desfecho e encerra o acompanhamento.
- Uma queda do WebSocket tenta de novo com uma espera crescente (1, 2, 4, 8 e no máximo 15 s). Enquanto isso, o cartão avisa que a posição ao vivo não está disponível, e a tela continua se atualizando pelo polling de sempre: o ao vivo é um bônus, nunca a única fonte.
- A região `aria-live` anuncia só o que muda de verdade: cada 500 m a menos, a perda de sinal e o desfecho. O SVG é decorativo, e a animação do ponto obedece a `prefers-reduced-motion`.

## Acessibilidade

- Landmarks (`header`, `main`, a navegação "Principal") e um link de pular para o conteúdo, visível ao ganhar foco. O logo do cabeçalho é decorativo (`alt=""`): o nome ao lado já nomeia o link, e o leitor de tela não ouve "Tucano" duas vezes.
- Depois de toda navegação de verdade, o foco vai para o `h1` da tela (o título já é da tela, um só lugar cuida disso, no `ScreenRouter`); uma atualização de tela viva nunca rouba o foco. A primeira tela deixa o foco onde o navegador põe, porque ninguém navegou ainda. O título recebe o foco sem anel: ele não é algo que se ativa, e o WCAG pede o anel aos controles.
- O anel de foco é sempre visível e sempre em duas camadas: um anel na cor da superfície, depois um na cor do texto. Assim o contraste do anel nunca depende do que está atrás do elemento focado, nem um botão laranja.
- `prefers-reduced-motion: reduce` zera a duração de toda animação e transição.
- A tela anterior fica visível enquanto a próxima carrega (uma barra de progresso no topo); o esqueleto de carregamento só aparece na primeira tela.

## A home

A home usa o banner da identidade. O original mora em `docs/assets`, com 2688 px e 4 MB; a web serve uma cópia em WebP com 1600 px e menos de 60 KB, que o `make web-art` gera a partir dele junto com as ilustrações das telas e os ícones. O banner é decorativo (`alt=""`), vem com largura e altura para a página não pular, e o texto fica sobre o terço vazio dele em tela larga. No celular, o banner vai para baixo do texto, recortado em volta do tucano. Como o banner é claro nos dois temas, o herói é um cartão claro também no tema escuro, e o texto por cima dele fica na cor tinta da paleta.

## Tokens e tema

As cores são as da Tucano (`docs/assets/README.md`), como propriedades customizadas em `src/styles/tokens.css`: claro por padrão, escuro por `prefers-color-scheme` ou pelo botão do cabeçalho, que grava a escolha em `localStorage` (`theme/useTheme.ts`, tudo dentro de `try/catch`, porque navegação privada e cota cheia existem).

O laranja da marca (`beak`) nunca é texto, só fundo de botão com texto `ink`, como o guia de identidade pede. No tema escuro, `teal-deep` e `coral-deep` não valem mais como texto sobre `ink` (o contraste medido cai para 2,99:1 e 3,58:1, abaixo dos 4,5:1 que o WCAG pede); o CSS troca por um teal mais claro (`#3FC7C7`, 8,34:1) e pelo `coral` comum (4,66:1). As contas e as fontes de cada par estão comentadas em `src/styles/tokens.css`.

Tom (`neutral`, `waiting`, `info`, `success`, `danger`) nunca vira cor dentro de um componente: `theme/tone.ts` é a única tabela que faz essa conta, e um badge ou um aviso só perguntam a ela.

A fonte é a Nunito, self-hosted pelo `@fontsource-variable/nunito`: nenhum request sai para uma fonte externa.

## Testes

Vitest, `@testing-library/react` e `user-event`, ambiente `jsdom`. `test/support/fixtures.ts` lê os exemplos reais de `contracts/http/bff/examples` do caminho do repositório, sem copiar o JSON: uma asserção lê o valor esperado do próprio fixture (o título, a mensagem de erro, o `detail` do problema), nunca reescreve o texto à mão, para não descolar quando o BFF mudar um exemplo.

| Teste | O que cobre |
|---|---|
| `screens/registry.test.ts` | a classe certa pega o componente certo, e uma classe desconhecida cai no genérico |
| `screens/examples.test.tsx` | toda tela de exemplo do contrato renderiza, cada uma com seu título como `h1`, com um WebSocket inerte no lugar do de verdade |
| `screens/tracking-screen.test.tsx` | o cartão ao vivo aparece só quando a tela oferece o link `rel-live` |
| `screens/order-screen.test.tsx` | a frase de agora, o histórico com as etapas da entrega e o motivo de cada passo, o entregador ao vivo no pedido, o aviso sem as notícias da entrega e o pagamento só enquanto o pedido espera |
| `screens/orders-screen.test.tsx` | cada pedido da lista tem o título como único link, a paginação, e a lista vazia com o tucano e o caminho para o catálogo |
| `screens/profiles-screen.test.tsx` | o perfil que está comprando marcado, a troca nos outros, o formulário de perfil novo, e a troca levando aos pedidos de quem foi escolhido |
| `components/header.test.tsx` | os links e o chip do perfil vêm da navegação da tela, o `aria-current` da seção, o convite para entrar sem sessão, e só a marca e o tema num problema |
| `components/order-progress.test.tsx` | cada marco com o seu estado, forma e palavras, `aria-current="step"` só no atual, a hora só do que já aconteceu, e o pedido cancelado parando no marco interrompido |
| `components/live-delivery.test.tsx` | o endereço sai do href com o esquema certo, a posição vira distância, o fim encerra, a queda reconecta com espera crescente, o aviso de sem sinal aos 30 s e o fechamento ao desmontar |
| `components/action-form.test.tsx` | um formulário nasce dos campos de uma ação, com rótulo, obrigatoriedade e opções de `select` |
| `screens/place-order.test.tsx` | o POST de `place-order` leva os campos ocultos e os digitados, segue o `Location` de um 201, e um 422 mostra cada mensagem, o resumo e o foco no primeiro campo inválido |
| `hypermedia/live.test.tsx` | uma tela viva busca de novo o `self` depois do intervalo, e pausa e retoma com a visibilidade da aba |
| `hypermedia/prefix.test.ts` | a ida e volta entre caminho do navegador e endereço do BFF |
| `components/link.test.tsx` | clique simples navega pelo `fetch`; clique com Ctrl não é interceptado; link `external` sai como âncora comum |
| `hypermedia/no-hardcoded-urls.test.ts` | nenhum arquivo além de `hypermedia/prefix.ts` tem o literal `/bff/v1` |

## Rodando os checks

```bash
make check s=web      # roda dentro do node:24-alpine: npm ci e npm run check
cd services/web && npm run dev      # a stack local não tem alvo de dev no Makefile
```

| Script | O que faz |
|---|---|
| `npm run check` | lint, typecheck, testes e build, na mesma ordem do CI |
| `npm test` | `vitest run` |
| `npm run typecheck` | `tsc --noEmit` |
| `npm run lint` | Biome: formatação, lint e ordem dos imports |
| `npm run format` | aplica as correções do Biome |
| `npm run dev` | Vite em modo de desenvolvimento, com proxy de `/bff` e de `/api/tracking` (com WebSocket) para `http://localhost:8000` (o Kong local) |
| `npm run build` | build de produção em `dist/` |
| `npm run preview` | serve o `dist/` para conferir o build antes de publicar |

Ao contrário do `bff` e do `partners-sim`, aqui existe uma etapa de build de verdade: o Vite empacota TypeScript e JSX em arquivos estáticos, porque é isso que o navegador precisa. Por isso o `tsconfig.json` usa `moduleResolution: bundler` e permite importar com a extensão `.ts`/`.tsx` (`allowImportingTsExtensions`), em vez do jeito sem build do `bff`. O resto da disciplina de tipos é a mesma: `strict`, `noUncheckedIndexedAccess`, sem `enum` nem `namespace`.

A imagem sai de `docker build -f services/web/Dockerfile -t chaos-playground/web .`, da raiz do repositório. Ela tem duas etapas: `node:24-alpine` builda o `dist/`, e só ele atravessa para a imagem final, `nginx:stable-alpine`. O nginx serve os arquivos com cabeçalhos de segurança (`Content-Security-Policy` restrito ao que o app usa, `X-Content-Type-Options`, `Referrer-Policy`), cache de um ano para o que o Vite já marca com hash no nome (`assets/`) e revalidação para o resto (o `index.html`, os ícones, o manifesto), com fallback para `index.html` em qualquer caminho que não seja um arquivo, porque quem decide o que mostrar ali é o React, não o nginx.
