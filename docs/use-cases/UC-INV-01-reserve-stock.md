# UC-INV-01: Reservar estoque

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (Ordering, dentro do UC-ORD-01) |
| **Escopo** | Commerce (Inventory) |
| **Gatilho** | um pedido novo precisa segurar os itens até o pagamento |

## Partes interessadas e interesses

- **Cliente**: receber tudo de uma vez, saindo de um CD só.
- **Tucano**: não vender a mesma unidade duas vezes (overselling) e não deixar estoque preso sem pedido.
- **Logística**: cada pedido sai de um único CD; não existe remessa dividida.

## Pré-condições

- A transação do pedido está aberta, e a reserva entra nela.

## Garantias mínimas

- Ou todos os itens ficam presos no mesmo CD, ou nenhum fica.
- `stock_items.reserved` nunca passa de `on_hand`; o `CHECK` do banco é a última barreira.

## Garantias de sucesso

- Cada item fica somado em `reserved` no CD escolhido, com uma linha `active` em `stock_reservations` até o prazo do pedido.

## Cenário principal de sucesso

1. O Ordering envia os itens, o estado de destino e o prazo da reserva.
2. O sistema ordena os CDs: primeiro os do estado de destino, depois os outros por código.
3. O sistema abre um savepoint e segura cada item no primeiro CD da lista.
4. O sistema registra as reservas e devolve o CD escolhido.

## Extensões

- 3a. Falta algum item no CD: o sistema volta ao savepoint, devolvendo o que já tinha segurado ali, e repete o passo 3 no próximo CD.
- 3b. Nenhum CD tem todos os itens: o sistema recusa a reserva dizendo o que faltou em cada CD (`409`), e o pedido não é criado.
- 3c. Disputa pela última unidade: a estratégia de reserva decide quem leva (veja as variações do UC-ORD-01).
- 4a. O pedido não existe quando a transação termina: a chave estrangeira adiada de `stock_reservations` recusa o `COMMIT`.

## Variações de tecnologia

- A estratégia padrão (`atomic`) confere e segura numa instrução só: `UPDATE stock_items SET reserved = reserved + n WHERE on_hand - reserved >= n`. As outras estratégias do laboratório entram pelo mesmo port, `ForHoldingStock`.
- A reserva é gravada antes do pedido, porque o CD só é conhecido depois dela. A chave estrangeira para `orders` é `DEFERRABLE INITIALLY DEFERRED` e só é conferida no `COMMIT`.

## No código

- Port `ForReservingStock` (Inventory), caso de uso `ReserveStock`, pacote `Commerce\Inventory`.
- O Ordering chega nele pelo adapter `InventoryStockReservations`, que implementa o port de saída `ForReservingStock` do próprio Ordering. Se o Inventory virar um serviço separado, só esse adapter muda.
