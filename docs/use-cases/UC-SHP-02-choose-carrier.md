# UC-SHP-02: Escolher a transportadora

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Logistics (Shipping, dentro do UC-SHP-01) |
| **Escopo** | Logistics (Carrier Selection) |
| **Gatilho** | uma remessa nova precisa de alguém que a leve |

## Partes interessadas e interesses

- **Cliente**: receber rápido. Dentro do estado do CD, a frota própria costuma chegar antes.
- **Tucano**: usar a frota própria quando ela resolve e não pagar frete pesado pelo que cabe num parceiro comum.
- **Transportadoras**: receber só o peso que aceitam levar.

## Pré-condições

- As tabelas `carriers` e `fulfillment_centers` estão preenchidas (seeders).
- O peso total já foi calculado a partir dos volumes da remessa (UC-SHP-01).

## Garantias mínimas

- Nenhuma transportadora recebe mais peso que o `max_weight_grams` dela.
- Sem transportadora que sirva, a remessa não é criada.

## Garantias de sucesso

- O código da transportadora escolhida pelo primeiro elo da corrente que encontrou uma.

## Cenário principal de sucesso

1. O Shipping envia o CD de origem, o estado de destino e o peso total.
2. O sistema busca o estado do CD de origem em `fulfillment_centers`.
3. O sistema pergunta o modo de despacho do momento (`DispatchMode`) e monta a corrente de regras dele: frota própria, parceiros regulares e frete pesado.
4. A frota própria (`tucano-express`) fica com a remessa, porque o destino está no estado do CD e o peso cabe no limite dela.
5. O sistema devolve o código da transportadora.

## Extensões

- 2a. CD fora da tabela: o sistema recusa com `UnknownFulfillmentCenter`, e a mensagem vai para a DLQ, porque repetir não resolve.
- 3a. Modo `partners_only` (a flag `logistics.own-fleet-dispatch` desligada, ou o flagd sem responder): a corrente começa nos parceiros. É o comportamento conservador, porque parceiro entrega em qualquer lugar.
- 4a. Destino em outro estado, ou peso acima do limite da frota: leva o parceiro com o menor limite que ainda comporta o peso. No empate, vence o menor código (`correio-nacional` antes de `ligeirinho`).
- 4b. Nenhum parceiro regular comporta o peso: a remessa vai de frete pesado (`carga-pesada`).
- 4c. Nem o frete pesado comporta: o sistema recusa com `NoCarrierFits`, a remessa não é criada e a mensagem vai para a DLQ, para uma pessoa olhar.

## Variações de tecnologia

- A corrente é um Chain of Responsibility. Cada regra é uma classe pequena (`OwnFleet`, `RegularPartners`, `HeavyFreight`) que escolhe uma transportadora ou passa a vez para a próxima. Quem monta a corrente é o próprio `DispatchMode`, um enum rico: cada modo sabe a corrente que roda, e ninguém precisa de um `if` em booleano para isso.
- O modo vem da flag `logistics.own-fleet-dispatch`, mas o caso de uso não sabe disso: ele pergunta ao port `ForChoosingDispatchMode`, e só o adapter `FlaggedDispatchMode` conhece o flagd. Trocar a flag por uma tabela ou por uma tela de operação muda uma classe.
- Os limites de peso vêm da tabela `carriers`, e as regras ficam no código. Mudar o limite de uma transportadora é um `UPDATE`, sem deploy.
- Escolher a menor transportadora que comporta o peso deixa os caminhões grandes livres para a carga que só eles levam.

## No código

- Port `ForChoosingCarriers`, caso de uso `ChooseCarrier`, pacote `Logistics\CarrierSelection`. O modo de despacho é o enum `DispatchMode`, do domínio, e chega pelo port `ForChoosingDispatchMode` (adapter `FlaggedDispatchMode`).
- O Shipping chega nele pelo adapter `CarrierSelectionChoices`, que implementa o port de saída `ForChoosingCarriers` do próprio Shipping. Se a escolha de transportadora virar um serviço, só esse adapter muda.
