# 0024. Dado sensível passa por um proxy

- Status: aceito
- Data: 2026-09-28

## Contexto

O commerce e a logistics guardam dado pessoal: o nome e o e-mail de quem compra, o nome e o documento de quem recebe a encomenda. O commerce também recebe o token do cartão, que não é o número do cartão, mas cobra o cartão. Esses valores viviam em `string` e em objetos que se imprimiam por inteiro, então bastava um log descuidado, um `var_dump` numa depuração ou uma mensagem de erro para eles aparecerem onde não deviam.

Uma auditoria achou dois vazamentos concretos:

- A mensagem de e-mail inválido repetia o e-mail recebido.
- A mensagem de token de cartão inválido repetia o que chegou. Se alguém mandasse um número de cartão no lugar do token, ele voltava na resposta e ia parar no log. Havia até um teste que cobrava esse eco.

A LGPD pede necessidade (art. 6º, III): usar só o dado que a finalidade exige. O PCI DSS pede que dado de cartão não se espalhe por onde não precisa. Nenhum dos dois se resolve só com boa vontade em cada linha de log.

## Decisão

- O shared kernel ganha o `Sensitive`, um proxy de proteção em volta do valor, e o `DataCategory`, um enum rico com as categorias (nome, e-mail, documento e token de cartão). Cada categoria sabe se mascarar e a que regra responde.
- Impresso, interpolado, logado, passado por `var_dump`, `print_r` ou `json_encode`, o valor mostra a máscara: `A*** S***`, `a***@example.com`, `***09`, `tok_***`. As máscaras têm largura fixa, para não contar o tamanho do que escondem.
- O valor de verdade só sai pelo `reveal()`, uma palavra fácil de achar numa revisão. A `serialize()` do PHP recusa o objeto, então ele não cai num cache ou numa fila sem alguém decidir isso.
- Os value objects do domínio guardam o valor dentro do proxy: `PersonName`, `EmailAddress` e `CardToken` no commerce, `Recipient` e `ProofOfDelivery` na logistics.
- O `reveal()` só aparece onde o valor precisa sair:
  - nos adapters: a linha do banco, a etiqueta que leva o nome até a porta, a chamada ao PSP;
  - nos eventos de domínio: a linguagem publicada que o outro contexto precisa;
  - em duas impressões digitais de idempotência. Nelas, a máscara colidiria: `A*** S***` é Ana Souza e também Ana Silva.
- Uma fitness function em cada serviço (`SensitiveDataLeavesOnPurposeTest`) falha quando um `reveal()` aparece em qualquer outro lugar.
- A leitura pública do pedido mostra o cliente mascarado. Sem login, quem tem o id do pedido vê o pedido, não quem comprou.

## Consequências

- Um log, um erro ou uma depuração deixam de expor dado pessoal por acidente, e o caminho do valor de verdade fica visível no código: é só procurar `reveal()`.
- O proxy protege a saída, não o armazenamento. O banco guarda o valor inteiro, e o evento `order.paid` leva nome e e-mail em claro para a logistics pelo Kafka. Criptografar em repouso, ou apagar por *crypto-shredding* quando alguém pede a exclusão (art. 18 da LGPD), são passos seguintes, com custo próprio.
- Ele para acidente, não intenção. `var_export()` e um cast para `array` ainda enxergam o valor. Quem quer vazar de propósito consegue, e contra isso servem revisão e controle de acesso.
- O endereço também é dado pessoal e segue aberto na leitura do pedido, porque é o que a pessoa confere antes de pagar. Com login, a leitura volta a mostrar tudo ao dono do pedido e a esconder de todo o resto.
- Cada valor sensível novo custa uma categoria no enum, se for de um tipo novo, e o uso do proxy no value object. A fitness function lembra do resto.

## Alternativas consideradas

- **Mascarar no logger** (um processor do Monolog que procura e-mails e números): pega o que conhece e deixa passar o que não conhece. Resolve o log e esquece as mensagens de erro, os dumps e as respostas da API.
- **Criptografar tudo no banco já**: resolve outra pergunta (quem lê o banco) e não impede o vazamento por log, que era o problema da auditoria. Fica registrado como passo seguinte.
- **Não guardar o dado**: o melhor dado pessoal é o que não existe. Mas a entrega precisa do nome e do e-mail, e o comprovante precisa de quem recebeu. O que dá para não guardar, a logistics já não guarda: quem recebeu nunca vai para um tópico.
