# UC-ORD-05: Consultar os próprios pedidos

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente |
| **Escopo** | Commerce (Ordering) |
| **Gatilho** | o cliente abre um pedido |

## Partes interessadas e interesses

- **Cliente**: ver o pedido que acabou de fazer, com status, itens, total e prazo para pagar e, depois que ele sai do CD, ir direto ao rastreio.
- **Tucano**: responder a mesma coisa que a criação do pedido respondeu, sem um segundo formato para manter.

## Pré-condições

- O cliente tem o id do pedido, que veio na resposta do UC-ORD-01 (header `Location`).

## Garantias mínimas

- Um id desconhecido, ou que nem é um UUIDv7, responde `404` e não diz mais nada.

## Garantias de sucesso

- O pedido volta no mesmo formato da resposta do UC-ORD-01, com o status atual.
- Depois da coleta, `trackingCode` traz o código da remessa, que abre a página de rastreio (UC-SHP-10). Antes disso ele vem `null`, e também fica `null` nos pedidos que saíram antes de o pedido guardar o código: a logística tem esses códigos, o pedido não.

## Cenário principal de sucesso

1. O cliente pede o pedido pelo id.
2. O sistema lê o pedido e devolve o estado atual.

## Extensões

- 2a. Pedido desconhecido: `404`.

## Variações de tecnologia

- A leitura vai no PostgreSQL, e não no read model do MongoDB, de propósito: quem acabou de pedir precisa ver o pedido na hora (read-your-writes), antes de qualquer projeção. A lista de pedidos do cliente vai ler o read model `order_views`, que aceita alguns segundos de atraso.

## No código

- Port `ForViewingOrders`, caso de uso `ViewOrder`, pacote `Commerce\Ordering`.
