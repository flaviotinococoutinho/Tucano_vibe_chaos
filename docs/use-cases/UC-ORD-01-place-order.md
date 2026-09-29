# UC-ORD-01: Fazer um pedido

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente |
| **Escopo** | Commerce (caixa-preta) |
| **Gatilho** | o cliente confirma o carrinho numa loja |

## Partes interessadas e interesses

- **Cliente**: garantir os itens pelo preço que viu, sem pedido duplicado se clicar duas vezes.
- **Loja**: vender só os próprios produtos, num pedido que é só dela ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)).
- **Tucano**: não vender o que não tem (overselling) nem abaixo do preço vigente.
- **Logística**: receber só pedidos com endereço entregável.

## Pré-condições

- Os produtos existem no snapshot local do catálogo, e cada um sabe a loja a que pertence ([UC-ORD-06](UC-ORD-06-sync-catalog.md)).

## Garantias mínimas

- Nenhuma reserva fica órfã: ou o pedido existe junto com a reserva, ou nada muda.
- Repetir a requisição com a mesma `Idempotency-Key` devolve o mesmo resultado.

## Garantias de sucesso

- Pedido `pending_payment` criado na loja em que foi feito, com número Snowflake, estoque reservado até `reservationExpiresAt` e `OrderPlaced` gravado na outbox. O pedido guarda a loja, e todo evento dele a leva em `store`.

## Cenário principal de sucesso

1. O cliente envia a loja em que está comprando (`store`, o slug dela), os itens, o endereço de entrega (logradouro, número em texto, complemento, divisões da UF ao bairro e CEP, como na [ADR 0020](../adr/0020-address-by-thoroughfare-and-divisions.md)) e uma `Idempotency-Key`.
2. O sistema valida os itens contra o snapshot do catálogo, confere que cada um é um produto daquela loja e congela os preços.
3. O sistema escolhe o centro de distribuição e reserva o estoque de cada item (UC-INV-01).
4. O sistema cria o pedido na loja, com número Snowflake, e registra `OrderPlaced` na outbox, com a loja, na mesma transação da reserva.
5. O sistema responde com o número do pedido, a loja, o total e o prazo para pagar.

## Extensões

- 1a. `Idempotency-Key` já usada com o mesmo corpo: o sistema devolve a resposta original.
- 1b. `Idempotency-Key` já usada com outro corpo, ou com os mesmos itens em outra loja: o sistema recusa (`422`).
- 1c. Endereço que ninguém entregaria (divisões fora da ordem, município de outra UF pelo geocódigo, número vazio ou enviado como número JSON): o sistema recusa (`422`) dizendo o que está errado.
- 1d. Sem loja, ou com uma loja que nem é um slug (`^[a-z][a-z0-9-]{1,30}$`): o sistema recusa (`422`) com o erro no campo `store`.
- 2a. Produto desconhecido ou descontinuado: o sistema recusa o pedido indicando o SKU (`409`, `product-unavailable`). O desconhecido é recusado antes de qualquer conferência de loja.
- 2b. Um item que não é produto da loja do pedido, porque é de outra loja ou porque a cópia do catálogo ainda não sabe a loja dele: o sistema recusa (`422`) com um erro no campo de cada item recusado, no mesmo formato de qualquer outro campo errado (`{"errors": {"items.1.sku": ["HOME-MUG-001 is not a product of arara."]}}`), e nada é reservado. Um produto de outra loja é recusado assim mesmo que esteja descontinuado, porque não cabe a esta loja vendê-lo. Uma loja que ninguém abriu, com um slug válido, tem todos os itens recusados desse jeito: o commerce conhece as lojas só pelos produtos.
- 3a. Estoque insuficiente em algum item: o sistema desfaz tudo e informa o que falta (`409`).
- 3b. Disputa pelo mesmo estoque: a estratégia de reserva configurada decide quem leva (veja o lab de overselling).
- 4a. Banco indisponível: nada é gravado e o cliente pode repetir com a mesma chave.

## Variações de tecnologia

- Idempotência: a chave entra em `idempotency_keys` na mesma transação do pedido. Uma segunda requisição com a mesma chave espera a primeira terminar e devolve o resultado dela, com o header `Idempotent-Replayed: true`; se a primeira falhou, a chave fica livre para uma nova tentativa, inclusive depois de um item recusado por ser de outra loja.
- Loja: o commerce não pergunta ao catálogo se a loja existe. A loja de cada produto na cópia local ([UC-ORD-06](UC-ORD-06-sync-catalog.md)) basta, e o checkout continua de pé com o catálogo fora. O pedido nasce na loja e nunca muda de loja; a coluna `orders.store` só fica nula nos pedidos de antes das lojas.
- Estratégias de reserva: `atomic` (padrão), `pessimistic`, `optimistic`, `serializable` e `naive` (só no laboratório, onde quero ver o lost update acontecer). Comparação e números no [laboratório de overselling](../labs/overselling.md).

## No código

- Port `ForPlacingOrders`, caso de uso `PlaceOrder`, pacote `Commerce\Ordering`. A loja é o value object `StoreSlug`, e o `CatalogProduct` responde se pertence a ela (`belongsTo`). Os itens de outra loja saem juntos no erro de domínio `ProductOfAnotherStore`, pela posição de cada um no pedido, e o `PlaceOrderController` os devolve como erros por campo (`items.N.sku`).
