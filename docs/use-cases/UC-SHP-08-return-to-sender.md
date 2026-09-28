# UC-SHP-08: Devolver ao remetente

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Transportadora (webhook do CarrierFake) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | o destinatário recusou a encomenda, ou a terceira visita não deu certo (`parcel.returning`) |

## Partes interessadas e interesses

- **Tucano**: a mercadoria de volta ao CD, para vender de novo.
- **Cliente**: se recusou ou não foi encontrado, o pedido não fica pendurado.

## Pré-condições

- A remessa está em `delivery_failed`, depois de uma recusa ou da terceira visita.

## Garantias mínimas

- A volta só começa quando pode: o guard `ReturnAllowed` exige a recusa ou a última visita.

## Garantias de sucesso

- Remessa `returning` e, quando os volumes chegam ao CD, `returned`; cada passo com o histórico e o evento na outbox (`ShipmentReturning`, `ShipmentReturned`).

## Cenário principal de sucesso

1. A transportadora avisa que os volumes começaram a voltar.
2. O sistema confere a assinatura, marca o evento na inbox, trava a remessa e move para `returning`.
3. A transportadora avisa que os volumes chegaram ao CD de origem.
4. O sistema move a remessa para `returned`.

## Extensões

- 2a. Ainda há visitas e ninguém recusou: o guard recusa, e a transportadora reenvia até desistir do evento.
- 4a. O commerce lê o `ShipmentReturned` e trata o pedido do lado dele (UC-ORD-04).

## No código

- Port `ForReturningToSender`, caso de uso `ReturnToSender`, pacote `Logistics\Shipping`.
