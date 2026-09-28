# UC-SHP-05: Registrar passagem por hub

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Transportadora (webhook do CarrierFake) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | um hub de triagem da transportadora escaneia os volumes (`parcel.hub_scanned`) |

## Partes interessadas e interesses

- **Cliente**: ver por onde a encomenda está passando.
- **Operador logístico**: enxergar em que hub as encomendas param.

## Pré-condições

- A remessa está em `picked_up` ou já em `in_transit`. A frota própria entrega no estado do CD e não passa por hub.

## Garantias mínimas

- Passagem sem hub não entra: o guard `HubRequired` recusa.
- O mesmo evento enviado duas vezes não registra duas passagens (inbox).

## Garantias de sucesso

- Remessa `in_transit`, com o hub no histórico (`location`) e o `ShipmentInTransit` na outbox, com o hub.

## Cenário principal de sucesso

1. A transportadora avisa a passagem pelo hub, com o nome dele.
2. O sistema confere a assinatura, marca o evento na inbox e trava a remessa pelo código de rastreio.
3. O sistema move a remessa para `in_transit` pela corrente de guards.
4. O sistema grava o estado, a linha do histórico com o hub e o evento na outbox.

## Extensões

- 1a. Um parceiro passa por dois hubs quando o destino é outro estado: o do estado de origem e o do estado de destino. Cada passagem é uma linha no histórico.
- 1b. O evento chega sem hub: `400`.
- 3a. O evento chega antes da coleta (o `parcel.picked_up` se perdeu ou ainda não chegou): `409`, e a transportadora reenvia depois.
- 3b. O evento chega depois da saída para entrega (a remessa pulou esse hub porque o webhook dele se perdeu): `200` com `obsolete`, o evento fica marcado na inbox e nada muda.

## No código

- Port `ForRecordingHubScans`, caso de uso `RecordHubScan`, pacote `Logistics\Shipping`.
