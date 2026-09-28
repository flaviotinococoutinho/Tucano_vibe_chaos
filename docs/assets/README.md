# Identidade visual

<img src="tucano.png" alt="O tucano da Tucano, de perfil: corpo preto, uma mancha clara em volta do olho e o bico laranja com a ponta teal, impresso em três tintas sobre papel creme" width="220" align="right">

A Tucano tem um mascote: um tucano de perfil, feito de poucas formas chapadas e impresso como numa risografia, com três tintas sobre papel creme, o grão do papel e o leve desencontro de uma gráfica pequena. Eu queria um desenho que parecesse feito à mão, com uma ideia só e nenhum brilho, e não mais uma ilustração que some no meio de tantas parecidas.

Ele aparece no README, nos capítulos do guia e nas telas da web. Em cada desenho ele faz uma coisa só, e essa coisa é a ideia do lugar onde ele está: pula a pedra que falta no rio (o sistema segue quando uma peça some), leva a encomenda pela trilha (a jornada de um pedido), empilha blocos de formas diferentes (as stacks), pendura mais um recibo no varal sem tirar nenhum (a informação como fatos que só se acumulam), puxa um plugue de propósito enquanto a lâmpada continua acesa (o caos com hipótese) e mora dentro de um hexágono com portas para o mundo (as abstrações).

## Cores

| Token | Cor | Onde usar |
|---|---|---|
| `ink` | `#1B1B1F` | texto e superfícies escuras |
| `paper` | `#FFF6E9` | fundo das telas e das ilustrações |
| `beak` | `#F28C28` | a cor da marca: botão principal, sempre com texto `ink` |
| `sun` | `#FFC83D` | destaque, selo, fundo de aviso, sempre com texto `ink` |
| `coral` | `#E4572E` | erro e perigo como fundo; como texto, o tom profundo |
| `teal` | `#138A8A` | informação e componentes; como texto, o tom profundo |
| `teal-deep` | `#0E6B6B` | link e texto informativo sobre `paper` |
| `coral-deep` | `#B83A1B` | texto de erro sobre `paper` |

O contraste foi medido pela fórmula do WCAG 2.2, e texto comum pede 4,5:1:

| Par | Contraste | Veredito |
|---|---|---|
| `ink` sobre `paper` | 16,03 | texto de tudo |
| `ink` sobre `beak` | 7,00 | botão principal |
| `ink` sobre `sun` | 11,10 | selos e avisos |
| `ink` sobre `coral` | 4,66 | alerta de erro |
| `teal-deep` sobre `paper` | 5,88 | links |
| `coral-deep` sobre `paper` | 5,36 | mensagem de erro de um campo |
| `beak` sobre `paper` | 2,29 | nunca como texto |
| `teal` sobre `paper` | 3,90 | só em ícone, borda ou texto grande |

No tema escuro, `paper` e `ink` trocam de lugar. `beak`, `sun` e `coral` funcionam como texto sobre `ink`, e o teal ganha um tom mais claro.

## Regras de uso

- O tucano não muda de cor: corpo `ink`, a mancha do olho no tom do papel e o bico `beak` com a ponta `teal`.
- As ilustrações usam três tintas, `ink`, `beak` e `teal`, sobre `paper`. `sun` e `coral` ficam para a interface: selo, aviso e erro.
- Uma ideia por ilustração, com bastante papel em volta. Se um desenho precisa de legenda para ser entendido, tem coisa demais nele.
- Nenhuma ilustração tem texto dentro. Título e legenda ficam no HTML ou no Markdown, onde dá para traduzir, buscar e ler com leitor de tela.
- Toda imagem tem `alt` descrevendo o que ela mostra, e não o que ela significa para o layout.
- As ilustrações trazem o próprio papel, então aparecem como um cartão claro de cantos arredondados, também no tema escuro.

## Arquivos

Os originais ficam aqui como chegaram, sem edição e com os metadados de procedência que vieram com eles. O README e o guia mostram os originais.

| Arquivo | O que mostra |
|---|---|
| [`tucano.png`](tucano.png) | o mascote, 1024 x 1024; o original de onde saem os ícones da web |
| [`banner.png`](banner.png) | o tucano pulando a pedra que falta no rio, com a encomenda no bico; no topo do README e na home da loja |
| [`guia/jornada.png`](guia/jornada.png) | a encomenda indo da loja até a casa pela trilha |
| [`guia/stacks.png`](guia/stacks.png) | blocos de formas diferentes, empilhados sem cair |
| [`guia/informacao.png`](guia/informacao.png) | o varal de recibos: cada fato novo entra no fim, e nenhum sai |
| [`guia/caos.png`](guia/caos.png) | um plugue puxado de propósito, e a lâmpada ainda acesa |
| [`guia/abstracoes.png`](guia/abstracoes.png) | o hexágono com o tucano dentro e as portas para o mundo |
| [`ui/empty.png`](ui/empty.png) | a sacola vazia, para uma lista sem nada |
| [`ui/not-found.png`](ui/not-found.png) | a placa de setas em branco, para o que não foi encontrado |
| [`ui/error.png`](ui/error.png) | o cabo cortado, para quando algo falhou |
| [`ui/success.png`](ui/success.png) | a encomenda fechada com laço, para o que deu certo |

## Do original para a web

A web não serve os originais: o banner tem 2688 px e 4 MB, e a home não precisa de nada disso. O `make web-art` roda o `scripts/web-art.py` num container Python e gera, em `services/web/public`:

- o banner em WebP, com 1600 px e menos de 60 KB, e as quatro ilustrações das telas em WebP, com 512 px e uns 16 KB cada;
- os ícones (o logo do cabeçalho, o favicon, o da tela inicial do iPhone e os dois do manifesto), recortados em volta do tucano e com uma paleta de 64 cores, que guarda as três tintas sem pesar.

Trocar uma ilustração é trocar o original aqui e rodar `make web-art`. As cópias vão para o Git junto, porque a imagem da web é construída sem Python.
