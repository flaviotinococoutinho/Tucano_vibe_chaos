# Identidade visual

<img src="tucano.png" alt="O tucano da Tucano: corpo grafite, peito creme, bico laranja com faixa amarela e ponta coral, capacete amarelo e uma caixa de papelão com um raio desenhado" width="220" align="right">

A Tucano tem um mascote: um tucano de capacete, com uma caixa de entrega debaixo da asa. O capacete é o laboratório, onde a gente quebra coisas de propósito com equipamento de segurança. A caixa é o negócio, uma loja com entrega própria. O raio na caixa é o caos controlado.

Ele aparece no README, nos capítulos do guia e nas telas da web: vazio, não encontrado, sucesso e erro.

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

- O tucano não muda de cor: o bico é laranja, amarelo e coral, e o corpo é grafite. Em fundo escuro, ele vai com a margem de respiro que já tem.
- Nenhuma ilustração tem texto dentro. Título e legenda ficam no HTML ou no Markdown, onde dá para traduzir, buscar e ler com leitor de tela.
- Toda imagem tem `alt` descrevendo o que ela mostra, e não o que ela significa para o layout.
- O traço é vetorial e chapado, com contorno escuro. Uma ilustração nova segue o mesmo estilo e a mesma paleta.

## Arquivos

| Arquivo | O que é |
|---|---|
| [`tucano.png`](tucano.png) | o mascote, 2048 x 2048, fundo transparente; o original de onde saem os ícones da web |
| [`banner.png`](banner.png) | o playground do caos, no topo do README |
| [`guia/caos.png`](guia/caos.png) | o tucano no painel de controle, derrubando servidores de propósito |
| [`guia/stacks.png`](guia/stacks.png) | o tucano regendo uma orquestra de máquinas diferentes |
| [`guia/informacao.png`](guia/informacao.png) | o cofre dos registros exatos e o rio das cópias que chegam depois |
| [`guia/jornada.png`](guia/jornada.png) | a encomenda voando da loja até a casa, passando pelo CD e pelo hub |
| [`guia/abstracoes.png`](guia/abstracoes.png) | a bagunça de cabos virando gavetas hexagonais arrumadas |
