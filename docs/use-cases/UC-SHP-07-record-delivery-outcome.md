# UC-SHP-07 · Registrar o resultado da entrega

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Entregador (app da frota) ou transportadora (webhook) |
| **Escopo** | Logistics |
| **Gatilho** | o entregador conclui uma tentativa no endereço |

## Partes interessadas e interesses

- **Cliente**: ter prova de que recebeu; se não estava em casa, ganhar outra chance.
- **Tucano**: no máximo três tentativas e depois devolução, para não queimar dinheiro com frete.
- **Entregador**: registrar rápido mesmo com sinal ruim, podendo reenviar sem duplicar nada.

## Pré-condições

- Remessa em `out_for_delivery`.

## Garantias mínimas

- O mesmo resultado enviado duas vezes não conta como duas tentativas (o id da tentativa é idempotente).

## Garantias de sucesso

- Remessa `delivered` com comprovante, ou `delivery_failed` com motivo; em ambos os casos, evento registrado na outbox.

## Cenário principal de sucesso

1. O entregador envia o resultado com o comprovante (nome e documento de quem recebeu).
2. O sistema valida a assinatura da requisição.
3. O sistema aplica a transição `out_for_delivery → delivered`, passando pela corrente de guards.
4. O sistema grava o novo estado, a linha de histórico e `ShipmentDelivered` na outbox.

## Extensões

- 1a. Destinatário ausente ou endereço não encontrado: transição para `delivery_failed`, com o motivo e a tentativa contada.
  - 1a1. Menos de três tentativas: a remessa volta para `out_for_delivery` no próximo despacho.
  - 1a2. Terceira tentativa ou recusa do destinatário: a remessa segue para `returning` (UC-SHP-08).
- 3a. Sem comprovante: o guard `ProofOfDeliveryRequired` recusa (422).
- 3b. Remessa em outro estado: `TransitionNotAllowed` (409). Se for a mesma tentativa repetida, o sistema responde como sucesso.

## No código

- Port `ForRecordingDeliveryOutcomes`, caso de uso `RecordDeliveryOutcome`, pacote `Logistics\Shipping`.
