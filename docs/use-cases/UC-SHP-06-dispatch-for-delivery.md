# UC-SHP-06: Despachar para entrega

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Transportadora (na frota própria, o operador que despacha o entregador) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | um entregador sai com os volumes para o endereço (`parcel.out_for_delivery`) |

## Partes interessadas e interesses

- **Cliente**: saber que a encomenda chega hoje.
- **Tucano**: no máximo três visitas ao endereço, porque cada uma custa frete.

## Pré-condições

- A remessa está em `picked_up` (frota própria), em `in_transit` (parceiros) ou em `delivery_failed` com visitas sobrando.

## Garantias mínimas

- Não existe quarta visita: o guard `AttemptsBelowLimit` recusa.

## Garantias de sucesso

- Remessa `out_for_delivery`, com o `ShipmentOutForDelivery` na outbox, carregando o número da visita.

## Cenário principal de sucesso

1. A transportadora avisa que o entregador saiu, com o número da visita.
2. O sistema confere a assinatura, marca o evento na inbox e trava a remessa pelo código de rastreio.
3. O sistema move a remessa para `out_for_delivery` pela corrente de guards.
4. O sistema grava o estado, o histórico e o evento na outbox.

## Extensões

- 3a. Já foram três visitas: o guard recusa, e nada muda.
- 3b. O evento chega fora de ordem (antes da coleta ou do hub): `409`, e a transportadora reenvia depois.

## No código

- Port `ForDispatchingDeliveries`, caso de uso `DispatchForDelivery`, pacote `Logistics\Shipping`.
