# Linguagem ubíqua

Os termos abaixo valem igualmente na conversa, na documentação e no código. Se um nome muda aqui, muda no código também.

## Glossário

| Termo | No código | Contexto | Significado |
|---|---|---|---|
| Produto | `Product` | Catalog | item vendável, identificado por SKU, com preço, peso e dimensões |
| SKU | `Sku` | Catalog, Commerce | código único de um produto (ex.: `BOOK-DDD-001`) |
| Snapshot de produto | `ProductSnapshot` | Commerce, Logistics | cópia local do estado do produto, recebida pelo tópico do catálogo |
| Pedido | `Order` | Ordering | intenção de compra do cliente, com itens e endereço de entrega |
| Número do pedido | `OrderNumber` | Ordering | identificador público do pedido (Snowflake em decimal) |
| Item do pedido | `OrderLine` | Ordering | SKU, quantidade e preço congelado no momento da compra |
| Centro de distribuição (CD) | `FulfillmentCenter` | Inventory, Logistics | armazém de onde os pedidos saem (`GRU1`, `BHZ1`) |
| Item de estoque | `StockItem` | Inventory | quantidade física e reservada de um SKU em um CD |
| Reserva | `StockReservation` | Inventory | quantidade separada para um pedido até ele ser pago ou expirar |
| Pagamento | `Payment` | Payments | tentativa de receber o valor de um pedido |
| Cobrança | `Charge` | Payments (ACL) | como o PSP chama um pagamento; só existe dentro do adapter |
| Estorno | `Refund` | Payments | devolução do valor pago |
| Remessa | `Shipment` | Shipping | o que precisa sair do CD e chegar ao cliente |
| Volume | `Parcel` | Shipping | pacote físico de uma remessa, com peso e dimensões |
| Código de rastreio | `TrackingCode` | Shipping | identificador público da remessa (Snowflake em Base32 de Crockford, ex.: `TX02PQRFBTW5G03`) |
| Transportadora | `Carrier` | Carrier Selection | quem transporta: frota própria ou parceira |
| Etiqueta | `ShippingLabel` | Labels | documento com código de barras colado no volume |
| Coleta | `Pickup` | Shipping | momento em que a transportadora retira a remessa no CD |
| Passagem por hub | `HubScan` | Shipping | leitura do código de barras num centro de triagem |
| Saiu para entrega | `OutForDelivery` | Shipping | a remessa está com o entregador da última milha |
| Tentativa de entrega | `DeliveryAttempt` | Shipping | cada ida ao endereço; no máximo três |
| Comprovante de entrega | `ProofOfDelivery` | Shipping | evidência do recebimento (nome e documento de quem recebeu) |
| Devolução ao remetente | `ReturnToSender` | Shipping | caminho de volta ao CD depois de tentativas sem sucesso |
| Entregador | `Courier` | Tracking | pessoa da frota própria com o app de GPS |
| Despacho | `Dispatch` | Tracking | atribuição de uma remessa ao entregador disponível mais próximo |
| Posição | `Position` | Tracking | latitude e longitude de um entregador num instante |

## Mesmo nome, modelos diferentes

Cada contexto tem seu próprio modelo, mesmo quando o nome coincide.

- **Centro de distribuição**: no Inventory é onde fica o estoque (SKU e quantidades); na Logistics é um ponto no mapa com horário de coleta.
- **Cliente**: no Ordering tem nome, e-mail e endereço de entrega congelados no pedido; no Tracking nem existe, só há quem acompanha um código de rastreio.
- **Pedido e remessa**: a logística não conhece o pedido; guarda só o `orderId` como referência e trabalha com a remessa.

## Palavras a evitar

| Evite | Prefira | Por quê |
|---|---|---|
| `item` sozinho | `OrderLine`, `Parcel`, `StockItem` | `item` significa coisas diferentes em cada contexto |
| `status` booleano (`isPaid`, `delivered`) | um estado da máquina (`OrderStatus::Paid`) | flags combinadas criam estados impossíveis |
| `data`, `info`, `manager`, `helper` | o nome do conceito | nomes genéricos escondem responsabilidade |
| evento no sentido de show ou promoção | - | aqui, evento é sempre **evento de domínio** |
