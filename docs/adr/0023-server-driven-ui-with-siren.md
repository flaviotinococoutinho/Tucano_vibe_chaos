# 0023. Telas dirigidas pelo servidor, com hipermídia (Siren)

- Status: aceito
- Data: 2026-09-28

## Contexto

A web vai mostrar a jornada inteira: o catálogo, o pedido, o pagamento, a espera pela confirmação, a entrega andando. Essa jornada muda conforme o estado de cada coisa: o botão de pagar só existe enquanto o pedido espera pagamento, e o link de rastreio só existe depois que a remessa sai. Se a web decidir isso sozinha, ela copia as regras dos serviços, e as duas cópias se desencontram no primeiro ajuste.

## Decisão

- O BFF responde telas em [Siren](https://github.com/kevinswiber/siren) (`application/vnd.siren+json`): o conteúdo (`properties`), os componentes (`entities`), para onde dá para ir (`links`) e o que dá para fazer (`actions`, com os campos do formulário).
- A web é um intérprete: um registro liga cada `class` a um componente React, e uma entidade de classe desconhecida cai num renderizador genérico, que mostra as propriedades, os links e as ações. Ela segue links e envia ações. Ela não monta URL, e o fluxo não mora nela.
- O que muda de tela para tela vem pronto do servidor: o dinheiro formatado, o rótulo do status e o tom (`waiting`, `success`). A web decide a forma, e o servidor decide o conteúdo e o próximo passo.
- As relações seguem a RFC 8288 (as registradas pelo nome, as do domínio como URI com definição no contrato), e os erros seguem a RFC 9457.
- O contrato está em `contracts/http/bff`, com exemplos reais validados por JSON Schema no CI.

## Consequências

- Uma regra de fluxo muda num lugar só. Liberar o pagamento por Pix, por exemplo, é uma ação nova na tela do pedido, e a web desenha o formulário sem deploy.
- A mesma tela serve a uma web, a um app ou a um teste de ponta a ponta que só segue links.
- As respostas pesam mais que um JSON cru, porque carregam links, ações e rótulos. Numa API pública isso contaria; entre o BFF e a própria web, não conta.
- Siren tem pouca ferramenta pronta: o intérprete e os testes de contrato são nossos.
- A web precisa de disciplina: um `fetch('/bff/v1/orders/' + id)` escrito à mão quebra a promessa. O teste do intérprete e a revisão cuidam disso.

## Alternativas consideradas

- **GraphQL**: o cliente escolhe os campos numa ida só, com um schema tipado. É ótimo quando cada tela é diferente e o cliente sabe o que quer. Aqui, o que eu queria era o contrário: que o servidor dissesse o próximo passo. Com GraphQL, o fluxo voltaria para o cliente.
- **HAL com HAL-FORMS**: é mais popular que Siren para links, mas as ações ficam num formato à parte (`_templates`). Siren tem entidade, link e ação no mesmo modelo, que é exatamente a tela.
- **Um formato próprio de server-driven UI**, como o dos apps grandes: dá liberdade total de layout, ao custo de manter uma especificação só nossa. Preferi um formato existente e documentado, com uma extensão pequena (tom, dinheiro formatado, telas vivas).
