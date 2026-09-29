# UC-TRK-01: Informar a posição do entregador

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Aparelho do entregador (simulado pelo partners-sim) |
| **Escopo** | Tracking |
| **Gatilho** | uma encomenda da frota própria saiu para entrega |

## Partes interessadas e interesses

- **Cliente**: ver onde está a encomenda dele, com poucos segundos de atraso.
- **Entregador**: que a posição dele só sirva para a entrega em curso, e só enquanto ela dura.
- **Tucano**: receber milhares de posições por segundo sem guardar uma história que ninguém pediu.

## Pré-condições

- O aparelho conhece o segredo compartilhado (`COURIERS_SECRET`) e o código de rastreio da encomenda que leva.

## Garantias mínimas

- Uma posição sem assinatura válida nunca é guardada nem repassada.
- O tracking guarda só a última notícia de cada código, por 15 minutos, e nenhuma história.

## Garantias de sucesso

- A posição vira a última notícia do código e chega a todos que acompanham aquele código, em qualquer worker de qualquer instância.

## Cenário principal de sucesso

1. O aparelho manda `POST /v1/positions` com uma notícia de entrega e a assinatura `Courier-Signature`.
2. O sistema confere a assinatura e a forma da notícia.
3. O sistema guarda a notícia como a última do código e a publica no canal `deliveries` do Redis.
4. O sistema responde `202`.
5. O aparelho repete a cada segundo até a porta, e então manda a notícia `ended`, com o desfecho da visita.

## Extensões

- 2a. Assinatura ausente, malformada, velha ou errada: o sistema responde `401` e descarta a notícia.
- 2b. A notícia não segue o contrato: o sistema responde `422`, com o erro de cada campo.
- 3a. O Redis está fora de alcance: o sistema responde `503`. O aparelho não repete, porque a próxima posição substitui esta.

## No código

- Pacote `Tracking\Delivery`: caso de uso `ReportDelivery`, port `ForReportingDeliveries`, controller `ReportDeliveryController` e o adapter `RedisDeliveryNews`. O protocolo está em [`contracts/tracking`](../../contracts/tracking/README.md).
