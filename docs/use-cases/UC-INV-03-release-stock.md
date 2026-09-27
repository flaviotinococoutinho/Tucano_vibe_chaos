# UC-INV-03: Liberar a reserva de um pedido

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (Ordering, dentro do UC-ORD-03 e, depois, do cancelamento pelo cliente) |
| **Escopo** | Commerce (Inventory) |
| **Gatilho** | um pedido que ainda segura estoque foi cancelado |

## Partes interessadas e interesses

- **Outros clientes**: as unidades voltam para venda na hora.
- **Tucano**: `reserved` continua igual à soma das reservas ativas.

## Pré-condições

- A transação do cancelamento está aberta, e a liberação entra nela.

## Garantias mínimas

- Liberar duas vezes não devolve unidade a mais: a segunda vez não encontra reserva ativa.

## Garantias de sucesso

- As reservas do pedido ficam `released`, e cada unidade volta para `stock_items`.

## Cenário principal de sucesso

1. O Ordering pede a liberação do pedido.
2. O sistema marca as reservas ativas como `released` e devolve as unidades, numa instrução só.

## Extensões

- 2a. O pedido não tem reserva ativa (já foi liberado ou nunca reservou): nada muda.

## Variações de tecnologia

- Um `WITH released AS (UPDATE stock_reservations ... RETURNING ...) UPDATE stock_items ... FROM released`. As duas tabelas mudam juntas no mesmo statement, sem janela entre elas.

## No código

- Port `ForReleasingStock`, caso de uso `ReleaseStock`, pacote `Commerce\Inventory`.
