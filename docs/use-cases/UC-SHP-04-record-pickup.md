# UC-SHP-04: Registrar a coleta

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Transportadora (webhook do CarrierFake) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | a etiqueta fica pronta (`ShipmentReadyForPickup`), e a transportadora vem buscar os volumes |

## Partes interessadas e interesses

- **Operador logístico**: a doca livre, com os volumes saindo no dia em que ficaram prontos.
- **Transportadora**: saber o que coletar, de onde e para onde, antes de sair com o caminhão.
- **Cliente**: a encomenda começa a andar sem ninguém pedir nada.

## Pré-condições

- A remessa está em `ready_for_pickup`, com a etiqueta gravada (UC-SHP-03).

## Garantias mínimas

- Uma coleta por remessa: o id da remessa é a `Idempotency-Key` do agendamento.
- O mesmo evento enviado duas vezes não muda nada: a inbox guarda o id do evento.
- Nenhum dado pessoal vai para a transportadora no agendamento: só origem, cidade, UF e CEP do destino, volumes e peso.

## Garantias de sucesso

- Coleta agendada na transportadora escolhida (UC-SHP-02); remessa `picked_up`, com o histórico e o `ShipmentPickedUp` na outbox, na mesma transação da marca na inbox.

## Cenário principal de sucesso

1. O worker `logistics-pickup-bookings` lê o `ShipmentReadyForPickup` em `logistics.shipments.v1`.
2. O sistema confere que a remessa ainda espera a coleta e agenda a coleta na transportadora da remessa.
3. A transportadora aceita a coleta como `scheduled`.
4. A transportadora coleta os volumes e avisa por webhook (`parcel.picked_up`).
5. O sistema confere a assinatura (`Carrier-Signature`), marca o evento na inbox, trava a remessa pelo código de rastreio e move para `picked_up`.

## Extensões

- 2a. A remessa já não espera a coleta (foi cancelada): nada é agendado, e o resultado é `not_needed`.
- 2b. A transportadora não responde: a partição de `logistics.shipments.v1` espera ela voltar ([ADR 0017](../adr/0017-wait-for-the-database-not-the-dlq.md)). O agendamento repetido cai na mesma coleta, pela chave.
- 2c. A transportadora recusa o agendamento (`4xx`): o evento vai para `dlq.logistics.pickup-bookings`, com um warning para uma pessoa olhar.
- 5a. Evento repetido: `200` com `duplicate`.
- 5b. Assinatura inválida, ou corpo que não é evento de transportadora: `400`, e nada muda.
- 5c. Código de rastreio que não é nosso: `200` com `unknown_shipment`.

## Variações de tecnologia

- O agendamento reage ao evento que a outbox já garantiu, como a etiqueta ([ADR 0018](../adr/0018-async-work-starts-from-the-event.md)), e a chamada à transportadora acontece fora de transação.
- A transportadora identifica a remessa pelo código de rastreio da etiqueta, e é por ele que o webhook trava a linha.

## No código

- Ports `ForBookingPickups` (caso de uso `BookPickup`) e `ForRecordingPickups` (caso de uso `RecordPickup`), pacote `Logistics\Shipping`. A transportadora é o adapter `CarrierFakePickups`, a ponte é o `PickupBookingHandler`, e o webhook é o `CarrierWebhookController`, que usa o `ShipmentProgress` comum a UC-SHP-04 a 08.
