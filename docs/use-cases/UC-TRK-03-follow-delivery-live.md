# UC-TRK-03: Acompanhar a entrega ao vivo

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente |
| **Escopo** | Tracking |
| **Gatilho** | o cliente abre o pedido enquanto a remessa está em rota |

## Partes interessadas e interesses

- **Cliente**: ver o entregador chegando.
- **Entregador**: não ter a posição exposta fora da entrega.
- **Tucano**: sustentar milhares de conexões simultâneas com pouca memória.

## Pré-condições

- Remessa despachada para um entregador da frota própria.

## Garantias mínimas

- O cliente só vê a posição do entregador da própria remessa, e só enquanto ela está em rota.

## Garantias de sucesso

- O navegador recebe cada nova posição do GPS do entregador.

## Cenário principal de sucesso

1. O cliente abre uma conexão WebSocket informando o código de rastreio.
2. O sistema identifica o entregador designado para aquela remessa.
3. O sistema envia a última posição conhecida.
4. A cada nova posição do entregador, o sistema envia a atualização para quem está acompanhando.
5. Quando a remessa é entregue, o sistema avisa e encerra a conexão.

## Extensões

- 2a. Remessa ainda não despachada: o sistema informa o estado e mantém a conexão aberta até o despacho.
- 4a. Entregador sem sinal há mais de 30 segundos: o sistema marca a posição como desatualizada.
- *a. O cliente perde a conexão: ao reconectar, recebe a última posição (volta ao passo 3).

## No código

- Pacote `Tracking\Delivery`: caso de uso `FollowDelivery`, port `ForFollowingDeliveries`, e o handshake no `FollowLiveController` (`GET /v1/live?trackingCode=`). Os seguidores de cada worker ficam no `LiveFollowers`, e o `DeliveriesSubscription` escuta o canal `deliveries` do Redis para empurrar cada notícia aos seguidores daquele worker. O protocolo está em [`contracts/tracking`](../../contracts/tracking/README.md), e a posição vem do [UC-TRK-01](UC-TRK-01-report-position.md).
- A remessa não tem um entregador designado no sistema (o UC-TRK-02 ainda não existe): o aparelho informa a posição pelo código de rastreio da encomenda que leva, e é por esse código que o cliente acompanha.
- O passo 2a não acontece na prática: o BFF só oferece o link ao vivo depois que a encomenda saiu para entrega, e a desatualização do passo 4a é decidida pela web, que compara o `at` da posição com o relógio.
