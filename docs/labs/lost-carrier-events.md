# Laboratório: os webhooks perdidos

A transportadora derruba parte dos avisos no caminho. O que acontece com as remessas?

Cada passo da jornada chega à logística por um webhook assinado da transportadora ([UC-SHP-04 a 08](../use-cases/README.md)), e a transportadora manda os eventos de uma encomenda um de cada vez. Um evento que chega antes da vez dele recebe `409`, e a transportadora reenvia depois. Esse cuidado com a ordem tem um preço: se um webhook some, todos os seguintes chegam adiantados, até a transportadora desistir deles. A remessa para no passo anterior ao perdido, mesmo com a encomenda entregue.

A saída é não depender só do empurrão. A transportadora guarda o histórico de cada coleta, e a conciliação ([UC-SHP-12](../use-cases/UC-SHP-12-reconcile-journeys.md)) puxa esse histórico quando a remessa fica quieta demais.

## As regras

| Peça | O que faz |
|---|---|
| CarrierFake | guarda cada evento da coleta antes de tentar o webhook: um webhook derrubado perde a mensagem, nunca o evento |
| webhook | aplica o passo pela máquina de estados, com a inbox; evento adiantado recebe `409`, repetido recebe `duplicate` |
| `logistics-journey-reconciler` | pega a remessa com a transportadora que passou 60 s sem notícia, lê o histórico e aplica em ordem, pelos mesmos casos de uso; a inbox descarta o que já tinha entrado |

## Como provocar

```bash
curl -s -X PUT localhost:4000/_chaos/carriers -H 'Content-Type: application/json' \
  -d '{"failureRate": 0.3, "refusalRate": 0.05, "webhooks": {"dropRate": 0.3, "duplicateRate": 0.2}}'
docker compose stop logistics-journey-reconciler    # para ver as remessas travarem
# pague uma leva de pedidos pelo Kong, com destinos em SP, MG, RJ e BA
docker compose up -d --no-deps logistics-journey-reconciler
curl -s -X DELETE localhost:4000/_chaos/carriers
```

Os destinos variados fazem a leva passar pela frota própria (dentro do estado do CD, sem hubs) e por uma parceira (com o hub do estado de origem e o do estado de destino).

## O que medi

**Sem a conciliação.** Mandei 12 pedidos, três para cada estado. A CarrierFake terminou as 12 jornadas: 54 eventos, 25 envios de webhook derrubados pelo caos, 5 enviados em dobro, 3 visitas com falha e 10 webhooks abandonados depois da última tentativa, quase todos os `409` que vieram atrás de um perdido. Do lado da logística, só 2 remessas bateram com a transportadora. As outras 10 ficaram paradas: 5 em `ready_for_pickup`, 1 em `in_transit` e 4 em `out_for_delivery`, todas já entregues na transportadora.

**Com a conciliação.** Liguei o worker. Em 45 s, 9 das 10 alcançaram a transportadora, com 26 passos aplicados. A décima parou com `stopped`:

```text
Shipment TX02PX83Y5M5G00 against its carrier: stopped, 0 steps applied
  before "Shipment TX02PX83Y5M5G00 is out_for_delivery and cannot move to in_transit."
```

**O furo que o caos achou.** A remessa era de uma parceira, de SP para RJ. O webhook do segundo hub scan se perdeu, e a saída para entrega entrou mesmo assim, porque a máquina deixa ir de `in_transit` direto para `out_for_delivery`: um hub é um passo que pode faltar. Na conciliação, aquele hub scan velho vinha antes da entrega no histórico, a máquina recusava a volta para `in_transit`, e a conciliação parava ali, rodada após rodada. O webhook atrasado teria o mesmo destino: `409` até a transportadora desistir.

Um hub scan depois da saída para entrega não é um passo, é notícia velha. Agora ele vira `obsolete`: fica marcado na inbox, não move a remessa, e a conciliação segue para o evento seguinte. Com a correção no ar, a remessa alcançou a transportadora na rodada seguinte, com 3 passos, e as 12 da leva passaram a bater.

**O que ainda precisa de alguém.** Cinco remessas antigas, de uma leva anterior, estavam paradas desde antes de a CarrierFake reiniciar. O simulador guarda o histórico em memória, e o restart apagou tudo, como a retenção de uma transportadora de verdade apagaria. Para essas, a conciliação volta toda vez com `up_to_date`: não há o que aplicar. Uma remessa que continua sem notícia e sem histórico por muito tempo é assunto para uma pessoa, e marcar isso é o próximo passo.

## O que levo disso

- Entrega em ordem e perda de mensagem, juntas, param o fluxo inteiro, e não só o evento perdido. Quem exige ordem precisa de um jeito de buscar o que falta.
- O empurrão (webhook) é rápido e o puxão (histórico) é certo. Com a inbox, os dois podem chegar ao mesmo passo sem aplicar nada duas vezes.
- Um passo opcional muda a leitura de "fora de ordem": o que veio depois dele pode já ter passado, e aí o certo é ignorar, não recusar.
