# UC-TRK-03 · Acompanhar a entrega ao vivo

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
4. A cada nova posição do entregador, o sistema empurra a atualização para quem está assistindo.
5. Quando a remessa é entregue, o sistema avisa e encerra a conexão.

## Extensões

- 2a. Remessa ainda não despachada: o sistema informa o estado e mantém a conexão esperando o despacho.
- 4a. Entregador sem sinal há mais de 30 s: o sistema marca a posição como desatualizada.
- *a. O cliente perde a conexão: ao reconectar, recebe a última posição (volta ao passo 3).

## No código

- Pacote `Tracking\Watch` (servidor WebSocket do Swoole), com Redis GEO e pub/sub.
