# Casos de uso

Os casos de uso seguem as ideias de **Alistair Cockburn**, autor de *Writing Effective Use Cases* e criador da Arquitetura Hexagonal. Nas duas obras a ideia central é a mesma: o sistema é descrito pelo que os **atores** querem alcançar, não pelas telas ou tabelas.

## Como ler

**Níveis de objetivo**, na metáfora do mar de Cockburn (nuvem, nível do mar e peixe):

| Nível | Pergunta que responde |
|---|---|
| resumo | qual é o objetivo de negócio maior, que atravessa vários casos de uso? |
| usuário | o que o ator quer resolver numa sentada, e sai satisfeito quando consegue? |
| subfunção | que passo reutilizável ajuda outros casos de uso? |

**Ficha completa** (*fully dressed*): ator principal, escopo, nível, partes interessadas e interesses, pré-condições, garantias mínimas, garantias de sucesso, gatilho, cenário principal e extensões. As extensões usam a numeração do passo que desviam (`3a`, `3b`).

**Do documento ao código**: cada caso de uso vira um **port de entrada** com o nome no estilo de Cockburn (`ForPlacingOrders`) e uma classe que o implementa (`PlaceOrder`), marcada com `#[UseCase('UC-ORD-01')]`. Um teste de arquitetura garante que toda classe marcada tem a sua ficha aqui.

## Lista ator-objetivo

| ID | Nível | Ator principal | Objetivo | Contexto |
|---|---|---|---|---|
| [UC-000](UC-000-buy-and-receive.md) | resumo | Cliente | comprar e receber um produto | todos |
| UC-CAT-01 | usuário | Cliente | consultar o catálogo | Catalog |
| UC-CAT-02 | usuário | Administrador do catálogo | publicar um produto | Catalog |
| UC-CAT-03 | usuário | Administrador do catálogo | alterar o preço de um produto | Catalog |
| UC-CAT-04 | usuário | Administrador do catálogo | descontinuar um produto | Catalog |
| [UC-ORD-01](UC-ORD-01-place-order.md) | usuário | Cliente | fazer um pedido | Ordering |
| UC-ORD-02 | usuário | Cliente | cancelar um pedido | Ordering |
| UC-ORD-03 | subfunção | Relógio | expirar pedidos não pagos | Ordering |
| UC-ORD-04 | subfunção | Logistics | refletir o andamento da remessa no pedido | Ordering |
| UC-ORD-05 | usuário | Cliente | consultar os próprios pedidos | Ordering |
| UC-INV-01 | subfunção | Commerce | reservar estoque | Inventory |
| UC-INV-02 | usuário | Operador logístico | repor estoque | Inventory |
| [UC-PAY-01](UC-PAY-01-pay-order.md) | usuário | Cliente | pagar um pedido | Payments |
| UC-PAY-02 | subfunção | PayFake | informar o resultado de um pagamento | Payments |
| UC-PAY-03 | subfunção | Relógio | conciliar pagamentos pendentes | Payments |
| UC-PAY-04 | subfunção | Commerce | estornar um pagamento | Payments |
| UC-NTF-01 | subfunção | Commerce | notificar o cliente | Notifications |
| [UC-SHP-01](UC-SHP-01-create-shipment.md) | subfunção | Commerce | criar a remessa de um pedido pago | Shipping |
| UC-SHP-02 | subfunção | Logistics | escolher a transportadora | Carrier Selection |
| UC-SHP-03 | subfunção | Fila de etiquetas | gerar a etiqueta | Labels |
| UC-SHP-04 | usuário | Transportadora | registrar a coleta | Shipping |
| UC-SHP-05 | usuário | Transportadora | registrar passagem por hub | Shipping |
| UC-SHP-06 | usuário | Operador logístico | despachar para entrega | Shipping |
| [UC-SHP-07](UC-SHP-07-record-delivery-outcome.md) | usuário | Entregador | registrar o resultado da entrega | Shipping |
| UC-SHP-08 | subfunção | Logistics | devolver ao remetente | Shipping |
| UC-SHP-09 | subfunção | Commerce | cancelar a remessa | Shipping |
| UC-SHP-10 | usuário | Cliente | rastrear pelo código | Shipping |
| UC-TRK-01 | usuário | Entregador | transmitir a posição | Tracking |
| UC-TRK-02 | subfunção | Logistics | encontrar o entregador disponível mais próximo | Tracking |
| [UC-TRK-03](UC-TRK-03-follow-delivery-live.md) | usuário | Cliente | acompanhar a entrega ao vivo | Tracking |
| UC-TRK-04 | usuário | Operador logístico | ver a frota no mapa | Tracking |

As fichas completas nascem junto com o código de cada caso de uso. Até lá, a linha da tabela é o contrato.

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
