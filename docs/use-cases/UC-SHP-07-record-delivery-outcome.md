# UC-SHP-07: Registrar o resultado da entrega

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Entregador, pela transportadora (webhook do CarrierFake) |
| **Escopo** | Logistics |
| **Gatilho** | o entregador termina uma visita ao endereço (`parcel.delivered` ou `parcel.delivery_failed`) |

## Partes interessadas e interesses

- **Cliente**: ter prova de que recebeu; se não estava em casa, ganhar outra chance.
- **Tucano**: no máximo três tentativas e depois devolução, para limitar o custo de frete.
- **Entregador**: registrar rápido mesmo com sinal ruim, podendo reenviar sem duplicar nada.
- **Destinatário**: o nome e o documento dele ficam na logística e não viajam nos eventos.

## Pré-condições

- Remessa em `out_for_delivery`.

## Garantias mínimas

- O mesmo resultado enviado duas vezes não conta como duas visitas: a inbox guarda o id do evento.

## Garantias de sucesso

- Remessa `delivered`, ou `delivery_failed` com o motivo; a visita fica em `delivery_attempts`, com o comprovante (nome e documento de quem recebeu) ou o motivo, e o evento vai para a outbox sem o nome nem o documento.

## Cenário principal de sucesso

1. A transportadora avisa a entrega, com o comprovante (nome e documento de quem recebeu).
2. O sistema confere a assinatura (`Carrier-Signature`), marca o evento na inbox e trava a remessa pelo código de rastreio.
3. O sistema move a remessa para `delivered` pela corrente de guards.
4. O sistema grava o estado, o histórico, a visita em `delivery_attempts` e o `ShipmentDelivered` na outbox, na mesma transação.

## Extensões

- 1a. Destinatário ausente ou endereço não encontrado: `delivery_failed`, com o motivo e a visita contada.
  - 1a1. Menos de três visitas: a remessa volta para `out_for_delivery` no próximo despacho (UC-SHP-06).
  - 1a2. Terceira visita ou recusa do destinatário: a remessa segue para `returning` (UC-SHP-08).
- 1b. O evento chega sem o comprovante ou sem o motivo: `400`, e nada muda.
- 3a. A remessa está em outro estado (o evento chegou antes da saída para entrega): `409`, e a transportadora reenvia depois, quando ele entra na vez dele.
- 3b. O mesmo evento de novo: `200` com `duplicate`.

## No código

- Port `ForRecordingDeliveryOutcomes`, caso de uso `RecordDeliveryOutcome`, pacote `Logistics\Shipping`. A visita é o `DeliveryAttempt` do agregado, que o repositório grava em `delivery_attempts`.
