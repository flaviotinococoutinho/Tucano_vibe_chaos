# UC-INV-04: Confirmar a venda do estoque

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Commerce (Ordering, dentro do UC-ORD-07) |
| **Escopo** | Commerce (Inventory) |
| **Gatilho** | um pedido com estoque reservado foi pago |

## Partes interessadas e interesses

- **Tucano**: o que foi vendido sai de `on_hand`, e `reserved` volta a contar só o que ainda espera pagamento.

## Pré-condições

- A transação do pagamento está aberta, e a confirmação entra nela.

## Garantias mínimas

- Confirmar duas vezes não tira unidade a mais: a segunda vez não encontra reserva ativa.
- `reserved <= on_hand` continua valendo, porque os dois diminuem juntos.

## Garantias de sucesso

- As reservas do pedido ficam `committed`, e cada unidade sai de `on_hand` e de `reserved`.

## Cenário principal de sucesso

1. O Ordering pede a confirmação do pedido pago.
2. O sistema marca as reservas ativas como `committed` e baixa o estoque, numa instrução só.

## Extensões

- 2a. O pedido não tem reserva ativa: nada muda.

## Variações de tecnologia

- Um `WITH committed AS (UPDATE stock_reservations ... RETURNING ...) UPDATE stock_items ... FROM committed`, como na liberação (UC-INV-03).

## No código

- Port `ForCommittingStock`, caso de uso `CommitStock`, pacote `Commerce\Inventory`.
