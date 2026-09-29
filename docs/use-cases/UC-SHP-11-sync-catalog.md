# UC-SHP-11: Manter peso, dimensões e loja dos produtos

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Catalog (pelo tópico `catalog.products.v1`) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | chega um snapshot de produto no tópico |

## Partes interessadas e interesses

- **Transportadoras**: receber volumes com o peso e o tamanho reais.
- **Tucano**: criar remessas mesmo com o catálogo fora do ar. A logística lê só a cópia local.
- **Lojas**: a remessa de um pedido de antes das lojas acha a loja pelos produtos (UC-SHP-01, [ADR 0031](../adr/0031-a-store-is-a-tenant.md)).

## Pré-condições

- Nenhuma. Um consumer group novo lê o tópico desde o início e monta a cópia inteira, porque o tópico é compactado e guarda o último estado de cada produto.

## Garantias mínimas

- A cópia nunca volta no tempo: um snapshot com versão igual ou menor que a guardada não muda nada. A única exceção é a loja, que não muda nunca: um snapshot da mesma versão que traz a loja que a cópia ainda não tem entra, e um snapshot sem loja nunca apaga a loja que a cópia conhece.

## Garantias de sucesso

- `product_snapshots` fica com o nome, a loja, o peso e as dimensões da versão mais nova de cada produto.

## Cenário principal de sucesso

1. O Catalog publica o estado completo de um produto que mudou.
2. O sistema lê só o que a logística usa (SKU, loja, nome, peso, dimensões e versão) e ignora o resto (tolerant reader).
3. O sistema grava o snapshot se a versão for mais nova que a da cópia, numa instrução só.

## Extensões

- 2a. Evento de outro tipo no tópico: o sistema ignora e segue.
- 2b. Tombstone (mensagem sem valor): o sistema ignora, porque o catálogo ainda não apaga produtos.
- 2c. Snapshot ilegível (sem peso, com dimensão zerada ou em texto, com peso que não cabe na coluna `integer`, com `productId` que não é UUID, com `store` fora do formato de slug): vai direto para `dlq.logistics.catalog-sync`, sem retry, porque repetir não conserta.
- 2d. Snapshot de antes das lojas, sem `store`: o produto entra na cópia sem loja, e fica assim até chegar um snapshot que diga a loja.
- 3a. Versão igual ou mais velha (entrega repetida ou fora de ordem): nada muda.
- 3b. Versão igual, agora com a loja que a cópia não tem: é o catálogo republicando os produtos depois que as lojas chegaram, sem mudança nenhuma que suba a versão. A cópia ganha a loja; o resto já era igual.
- 3c. Versão mais nova sem loja: o peso, as dimensões e o nome mudam, e a loja que a cópia conhece fica.

## Variações de tecnologia

- O caso de uso não precisa de inbox: o `INSERT ... ON CONFLICT DO UPDATE ... WHERE catalog_version < EXCLUDED.catalog_version` já torna a escrita idempotente e resistente à ordem de chegada. A mesma condição aceita a versão igual só quando ela preenche a loja, e o `coalesce` segura a loja conhecida.
- Produto descontinuado continua na cópia. A remessa de um pedido pago antes da descontinuação ainda precisa do peso dele.

## No código

- Port `ForSyncingCatalog`, caso de uso `SyncCatalogProduct`, pacote `Logistics\Shipping`. O consumidor Kafka é o adapter `CatalogSnapshotHandler`, rodando no comando `logistics:sync-catalog`.
