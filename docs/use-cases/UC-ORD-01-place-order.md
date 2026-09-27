# UC-ORD-01: Fazer um pedido

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente |
| **Escopo** | Commerce (caixa-preta) |
| **Gatilho** | o cliente confirma o carrinho |

## Partes interessadas e interesses

- **Cliente**: garantir os itens pelo preço que viu, sem pedido duplicado se clicar duas vezes.
- **Tucano**: não vender o que não tem (overselling) nem abaixo do preço vigente.
- **Logística**: receber só pedidos com endereço entregável.

## Pré-condições

- Os produtos existem no snapshot local do catálogo.

## Garantias mínimas

- Nenhuma reserva fica órfã: ou o pedido existe junto com a reserva, ou nada muda.
- Repetir a requisição com a mesma `Idempotency-Key` devolve o mesmo resultado.

## Garantias de sucesso

- Pedido `pending_payment` criado com número Snowflake, estoque reservado até `reservationExpiresAt` e `OrderPlaced` gravado na outbox.

## Cenário principal de sucesso

1. O cliente envia os itens, o endereço de entrega e uma `Idempotency-Key`.
2. O sistema valida os itens contra o snapshot do catálogo e congela os preços.
3. O sistema escolhe o centro de distribuição e reserva o estoque de cada item (UC-INV-01).
4. O sistema cria o pedido com número Snowflake e registra `OrderPlaced` na outbox, na mesma transação da reserva.
5. O sistema responde com o número do pedido, o total e o prazo para pagar.

## Extensões

- 1a. `Idempotency-Key` já usada com o mesmo corpo: o sistema devolve a resposta original.
- 1b. `Idempotency-Key` já usada com outro corpo: o sistema recusa (`422`).
- 2a. Produto desconhecido ou descontinuado: o sistema recusa o pedido indicando o SKU.
- 3a. Estoque insuficiente em algum item: o sistema desfaz tudo e informa o que falta (`409`).
- 3b. Disputa pelo mesmo estoque: a estratégia de reserva configurada decide quem leva (veja o lab de overselling).
- 4a. Banco indisponível: nada é gravado e o cliente pode repetir com a mesma chave.

## Variações de tecnologia

- Idempotência: a chave entra em `idempotency_keys` na mesma transação do pedido. Uma segunda requisição com a mesma chave espera a primeira terminar e devolve o resultado dela, com o header `Idempotent-Replayed: true`; se a primeira falhou, a chave fica livre para uma nova tentativa.
- Estratégias de reserva: `atomic` (padrão), `pessimistic`, `optimistic`, `serializable` e `naive` (só no laboratório, onde quero ver o lost update acontecer). Comparação e números no [laboratório de overselling](../labs/overselling.md).

## No código

- Port `ForPlacingOrders`, caso de uso `PlaceOrder`, pacote `Commerce\Ordering`.
