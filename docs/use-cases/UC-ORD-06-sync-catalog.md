# UC-ORD-06: Manter a cópia local do catálogo

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Catalog (pelo tópico `catalog.products.v1`) |
| **Escopo** | Commerce (Ordering) |
| **Gatilho** | chega um snapshot de produto no tópico |

## Partes interessadas e interesses

- **Cliente**: pagar o preço vigente e não conseguir comprar um produto descontinuado.
- **Loja**: vender só os próprios produtos: o checkout confere nesta cópia a loja de cada item ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)).
- **Tucano**: fechar pedido mesmo com o catálogo fora do ar; o checkout lê só a cópia local.

## Pré-condições

- Nenhuma. Um consumer group novo lê o tópico desde o início e monta a cópia inteira, porque o tópico é compactado e guarda o último estado de cada produto.

## Garantias mínimas

- A cópia nunca volta no tempo: um snapshot com versão igual ou menor que a guardada não muda nada. A única exceção é a loja, que um snapshot da mesma versão pode trazer (3b).
- Um produto nunca perde a loja que a cópia já sabe: a loja de um produto não muda.

## Garantias de sucesso

- `product_snapshots` fica com o nome, o preço, a moeda, o status e a loja da versão mais nova de cada produto.

## Cenário principal de sucesso

1. O Catalog publica o estado completo de um produto que mudou.
2. O sistema lê só os campos que o checkout usa, a loja entre eles, e ignora o resto (tolerant reader).
3. O sistema grava o snapshot se a versão for mais nova que a da cópia, numa instrução só.

## Extensões

- 2a. Evento de outro tipo no tópico: o sistema ignora e segue.
- 2b. Tombstone (mensagem sem valor): o sistema ignora, porque o catálogo ainda não apaga produtos.
- 2c. Snapshot ilegível (sem preço, status desconhecido, SKU fora do formato, loja que não é um slug): vai direto para `dlq.commerce.catalog-sync`, sem retry, porque repetir não conserta.
- 2d. Snapshot de antes das lojas, sem `store`: a cópia guarda o produto sem loja, e nenhum pedido o aceita até um snapshot dizer a loja dele ([UC-ORD-01](UC-ORD-01-place-order.md), extensão 2b).
- 3a. Versão igual ou mais velha (entrega repetida ou fora de ordem): nada muda.
- 3b. A mesma versão, agora com a loja que a cópia ainda não tinha: a cópia guarda a loja. É o caso do catálogo que deu loja aos produtos antigos e os publicou de novo sem mudar a versão, e não quero depender de ele mudar.
- 3c. Uma versão mais nova sem `store`: o resto do snapshot vale, e a loja que a cópia já sabia fica.

## Variações de tecnologia

- O caso de uso não precisa de inbox: o `INSERT ... ON CONFLICT DO UPDATE ... WHERE catalog_version < EXCLUDED.catalog_version` já torna a escrita idempotente e resistente à ordem de chegada. A mesma instrução cuida da loja: o `WHERE` também aceita a mesma versão quando a cópia não tem loja e o snapshot tem, e o `SET` faz `store = COALESCE(EXCLUDED.store, product_snapshots.store)`.
- A coluna `product_snapshots.store` é um `varchar(31)` com `CHECK` do formato do slug, e fica nula até um snapshot dizer a loja.

## No código

- Port `ForSyncingCatalog`, caso de uso `SyncCatalogProduct`, pacote `Commerce\Ordering`. O consumidor Kafka é o adapter `CatalogSnapshotHandler`, rodando no comando `commerce:sync-catalog`.
