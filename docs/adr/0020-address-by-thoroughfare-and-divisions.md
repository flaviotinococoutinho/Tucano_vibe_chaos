# 0020. Modelar o endereço por logradouro e divisões territoriais

- Status: aceito
- Data: 2026-09-27

## Contexto

O endereço nasceu com os campos de um formulário: `street`, `number`, `district`, `city` e `state`. Com ele eu tinha três problemas:

- **Rua é só um tipo de logradouro.** Avenida, rodovia, travessa, estrada, alameda e praça também são, e o campo `street` misturava o tipo com o nome ("Rua da Bahia").
- **O `district` guardava o bairro, mas distrito é outra coisa.** No IBGE, o país se divide em UF, município, distrito e subdistrito, e o bairro fica abaixo deles, desenhado por cada município. Com um campo por nível, cada divisão nova (ou cada país novo) vira coluna nova e contrato novo.
- **O número de um logradouro nem sempre é um número.** Numa rodovia ele é o quilômetro (`KM 100`), um lote pode não ter número (`S/N`) e muita casa tem letra (`120-A`).

## Decisão

- O endereço (`Address`) é o logradouro, o número, o complemento, as divisões territoriais, o CEP e, quando o cliente compartilha, as coordenadas.
- O logradouro (`Thoroughfare`) tem tipo e nome: `Rua` e `da Bahia`, `Rodovia` e `Fernão Dias`. O tipo é texto de até 30 caracteres, e não enum, porque nenhuma regra depende dele e cada país tem a sua lista.
- O número é texto de até 20 caracteres: `1200`, `KM 500`, `S/N`, `120-A`.
- As divisões (`Divisions`) são uma lista da maior para a menor, e cada uma tem tipo (`DivisionKind`), nome e um código oficial opcional. No Brasil: estado, município, distrito, subdistrito e bairro, nessa ordem, cada tipo uma vez. Estado e município são obrigatórios; os outros entram quando existem.
- O estado é conhecido pela sigla da UF. Município, distrito e subdistrito usam o geocódigo do IBGE, que cresce com o nível (7, 9 e 11 dígitos) e começa com o código de quem está acima: `31` é Minas Gerais e `3106200` é Belo Horizonte. O modelo confere esse prefixo, e com isso um município de São Paulo não passa como se fosse de Minas.
- As peças ficam no shared kernel (`Tucano\SharedKernel\Address`), como o `Money`: o commerce e a logística usam o mesmo endereço, e a forma dele é a linguagem publicada nos eventos dos dois.
- No banco, as divisões ficam numa coluna `jsonb` com CHECK da forma (uma lista que começa pelo estado, com a UF em duas letras, e pelo município), e o logradouro vira duas colunas, tipo e nome.
- A forma nova quebra os contratos que levam endereço. Pela [ADR 0010](0010-cloudevents-contracts.md), `commerce.orders` e `logistics.shipments` passam para `.v2`, e os consumidores leem as duas versões até a `.v1` esvaziar, traduzindo o endereço antigo para o novo.
- A API da transportadora não muda. O contrato é dela (`city`, `state` e `postalCode`), e o adapter entrega o nome do município no `city`.

## Consequências

- Uma divisão nova, como as regiões administrativas do DF, é um tipo a mais em `DivisionKind`, na posição dele, sem coluna e sem contrato novos. Outro país pede o país no endereço, o esquema de divisões dele e o formato do código postal dele.
- Uma consulta por UF no SQL lê o primeiro item da lista (`ship_divisions -> 0 ->> 'code'`), que o CHECK garante ser o estado.
- A migração separa o `street` gravado em tipo e nome pelos tipos conhecidos (Rua, Avenida, Rodovia, Estrada, Travessa, Alameda, Praça e as abreviações comuns). O que não começa com um deles fica como `Rua` com o texto inteiro no nome. O `district` antigo vira bairro, e o `city` vira município.
- Os tópicos `.v1` e `.v2` convivem por um tempo. A leitura da `.v1` sai quando o lag dela zerar em todos os consumer groups.

## Alternativas consideradas

- **Uma coluna por nível** (estado, município, distrito, subdistrito e bairro): o SQL fica mais simples, mas cada divisão nova vira migração e contrato, e o modelo não serve para outro país.
- **Tipo de logradouro como enum**: tipagem forte, mas a lista oficial tem dezenas de tipos, muda de país para país, e nenhuma regra do domínio decide pelo tipo.
- **Número inteiro com um modificador**, como no CNEFE do IBGE: fiel ao cadastro estatístico, mas complica o formulário e a etiqueta sem ganho para a entrega.
- **Mudança aditiva no `.v1`**, com os campos novos ao lado dos antigos marcados como obsoletos: o tópico ficaria o mesmo, mas o produtor teria que continuar calculando `street`, `district` e `city` até alguém criar o `.v2`.
- **Uma tabela de divisões ligada ao pedido**: normaliza, mas o endereço do pedido é um valor congelado, não uma entidade, e a lista em `jsonb` é lida e gravada de uma vez.
