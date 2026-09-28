# Casos de uso

Os casos de uso seguem **Alistair Cockburn**, autor do livro Writing Effective Use Cases e criador da Arquitetura Hexagonal. Nas duas obras a ideia central é a mesma: o sistema é descrito pelo que os atores querem alcançar, não por telas ou tabelas.

## Como ler

**Níveis de objetivo**, na metáfora do mar de Cockburn (nuvem, nível do mar e peixe):

| Nível | Pergunta que responde |
|---|---|
| resumo | qual é o objetivo de negócio maior, que atravessa vários casos de uso? |
| usuário | o que o ator quer resolver numa sentada, e sai satisfeito quando consegue? |
| subfunção | que passo reutilizável ajuda outros casos de uso? |

**Ficha completa** (fully dressed): ator principal, escopo, nível, partes interessadas e interesses, pré-condições, garantias mínimas, garantias de sucesso, gatilho, cenário principal e extensões. As extensões usam a numeração do passo de onde desviam (`3a`, `3b`).

**Do documento ao código**: cada caso de uso vira um port de entrada com nome no estilo de Cockburn (`ForPlacingOrders`) e uma classe que o implementa (`PlaceOrder`), marcada com `#[UseCase('UC-ORD-01')]`. Um teste de arquitetura garante que toda classe marcada tem a sua ficha aqui.

## Lista ator-objetivo

| ID | Nível | Ator principal | Objetivo | Contexto |
|---|---|---|---|---|
| [UC-000](UC-000-buy-and-receive.md) | resumo | Cliente | comprar e receber um produto | todos |
| [UC-CAT-01](UC-CAT-01-browse-catalog.md) | usuário | Cliente | consultar o catálogo | Catalog |
| [UC-CAT-02](UC-CAT-02-publish-product.md) | usuário | Administrador do catálogo | publicar um produto | Catalog |
| [UC-CAT-03](UC-CAT-03-change-price.md) | usuário | Administrador do catálogo | alterar o preço de um produto | Catalog |
| [UC-CAT-04](UC-CAT-04-discontinue-product.md) | usuário | Administrador do catálogo | descontinuar um produto | Catalog |
| [UC-ORD-01](UC-ORD-01-place-order.md) | usuário | Cliente | fazer um pedido | Ordering |
| UC-ORD-02 | usuário | Cliente | cancelar um pedido | Ordering |
| [UC-ORD-03](UC-ORD-03-expire-unpaid-orders.md) | subfunção | Relógio | expirar pedidos não pagos | Ordering |
| [UC-ORD-04](UC-ORD-04-follow-shipment.md) | subfunção | Logistics | refletir o andamento da remessa no pedido | Ordering |
| [UC-ORD-05](UC-ORD-05-view-orders.md) | usuário | Cliente | consultar os próprios pedidos | Ordering |
| [UC-ORD-06](UC-ORD-06-sync-catalog.md) | subfunção | Catalog | manter a cópia local do catálogo | Ordering |
| [UC-ORD-07](UC-ORD-07-settle-order-payment.md) | subfunção | Payments | registrar o resultado do pagamento no pedido | Ordering |
| [UC-INV-01](UC-INV-01-reserve-stock.md) | subfunção | Commerce | reservar estoque | Inventory |
| UC-INV-02 | usuário | Operador logístico | repor estoque | Inventory |
| [UC-INV-03](UC-INV-03-release-stock.md) | subfunção | Commerce | liberar a reserva de um pedido | Inventory |
| [UC-INV-04](UC-INV-04-commit-stock.md) | subfunção | Commerce | confirmar a venda do estoque | Inventory |
| [UC-PAY-01](UC-PAY-01-pay-order.md) | usuário | Cliente | pagar um pedido | Payments |
| [UC-PAY-02](UC-PAY-02-settle-payment.md) | subfunção | PayFake | informar o resultado de um pagamento | Payments |
| [UC-PAY-03](UC-PAY-03-reconcile-payments.md) | subfunção | Relógio | conciliar pagamentos pendentes | Payments |
| [UC-PAY-04](UC-PAY-04-refund-payment.md) | subfunção | Commerce | estornar um pagamento | Payments |
| UC-NTF-01 | subfunção | Commerce | notificar o cliente | Notifications |
| [UC-SHP-01](UC-SHP-01-create-shipment.md) | subfunção | Commerce | criar a remessa de um pedido pago | Shipping |
| [UC-SHP-02](UC-SHP-02-choose-carrier.md) | subfunção | Logistics | escolher a transportadora | Carrier Selection |
| [UC-SHP-03](UC-SHP-03-generate-label.md) | subfunção | Fila de etiquetas | gerar a etiqueta | Shipping |
| [UC-SHP-04](UC-SHP-04-record-pickup.md) | usuário | Transportadora | registrar a coleta | Shipping |
| [UC-SHP-05](UC-SHP-05-record-hub-scan.md) | usuário | Transportadora | registrar passagem por hub | Shipping |
| [UC-SHP-06](UC-SHP-06-dispatch-for-delivery.md) | usuário | Transportadora | despachar para entrega | Shipping |
| [UC-SHP-07](UC-SHP-07-record-delivery-outcome.md) | usuário | Entregador | registrar o resultado da entrega | Shipping |
| [UC-SHP-08](UC-SHP-08-return-to-sender.md) | subfunção | Transportadora | devolver ao remetente | Shipping |
| [UC-SHP-09](UC-SHP-09-cancel-shipment.md) | subfunção | Commerce | cancelar a remessa | Shipping |
| UC-SHP-10 | usuário | Cliente | rastrear pelo código | Shipping |
| [UC-SHP-11](UC-SHP-11-sync-catalog.md) | subfunção | Catalog | manter peso e dimensões dos produtos | Shipping |
| [UC-SHP-12](UC-SHP-12-reconcile-journeys.md) | subfunção | Relógio | conciliar a jornada com a transportadora | Shipping |
| UC-TRK-01 | usuário | Entregador | transmitir a posição | Tracking |
| UC-TRK-02 | subfunção | Logistics | encontrar o entregador disponível mais próximo | Tracking |
| [UC-TRK-03](UC-TRK-03-follow-delivery-live.md) | usuário | Cliente | acompanhar a entrega ao vivo | Tracking |
| UC-TRK-04 | usuário | Operador logístico | ver a frota no mapa | Tracking |

As fichas completas são escritas junto com o código de cada caso de uso. Até lá, a linha da tabela é o contrato.

## Atores

| Ator | Tipo | Observação |
|---|---|---|
| Cliente | pessoa | compra e acompanha |
| Administrador do catálogo | pessoa | mantém produtos e preços |
| Operador logístico | pessoa | acompanha remessas, estoque e frota |
| Entregador | pessoa, via app da frota | simulado pelo `partners-sim` |
| PayFake, Transportadora | sistemas externos | simulados pelo `partners-sim` |
| Commerce, Logistics | sistemas internos | disparam casos de uso de outro contexto por eventos |
| Relógio | tempo | Cockburn trata o tempo como ator em rotinas agendadas |
