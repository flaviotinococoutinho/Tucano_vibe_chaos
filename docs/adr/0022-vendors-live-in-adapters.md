# 0022. Fornecedor mora só nos adapters

- Status: aceito
- Data: 2026-09-28

## Contexto

O hexágono já separava o domínio do framework: o Deptrac proibia `Illuminate`, `Symfony` e afins no `Domain`. Mas uma auditoria achou duas brechas que a regra não via, porque SDK de terceiro não estava em camada nenhuma:

- Os eventos de domínio do commerce e da logistics geravam o próprio id chamando `Ramsey\Uuid` direto.
- O caso de uso `ChooseCarrier` perguntava à feature flag `logistics.own-fleet-dispatch`, e a camada de aplicação tinha licença para depender do pacote de flags.

Nenhuma das duas quebrava nada hoje. As duas prendiam o núcleo a uma escolha de infraestrutura que deveria poder mudar sozinha.

## Decisão

- O Deptrac ganha a camada `Vendor` (AWS, Guzzle, MongoDB, Ramsey, RdKafka, OpenFeature), e só `Adapter` e `App` podem usá-la. A camada de aplicação perde a licença de usar o pacote de flags.
- O id dos eventos vem do shared kernel (`EventId`), o único lugar que conhece a biblioteca de UUID.
- Uma decisão de operação que o domínio precisa vira um tipo do domínio, pedido por um port: o `DispatchMode` (enum rico, que sabe a corrente de regras de cada modo) chega pelo `ForChoosingDispatchMode`, e só o adapter `FlaggedDispatchMode` sabe que a resposta vem do flagd. É o mesmo desenho do `ForChoosingStrategy` do Inventory.

## Consequências

- Trocar um fornecedor muda uma camada: o Floci pela AWS de verdade, o flagd por um banco de configuração, a biblioteca de UUID por outra.
- Os testes do núcleo não precisam de dublê de fornecedor, só de dublê de port.
- Cada decisão nova desse tipo custa um port e um adapter a mais. É pouco, e o nome do port documenta a pergunta que o núcleo faz.
- A lista da camada `Vendor` precisa crescer junto com as dependências. Um SDK novo fora da lista passa despercebido, como os dois de antes.

## Alternativas consideradas

- **Aceitar biblioteca "neutra" no domínio** (UUID, data): é o que estava, e é defensável, porque a biblioteca é estável. Preferi a regra sem exceção, porque exceção vira precedente.
- **Abstrair cada SDK atrás de uma interface genérica** (`Storage`, `Queue`): esconde demais. O port certo é a pergunta do negócio (`ForStoringLabels`), não a capacidade técnica.
