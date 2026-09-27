# UC-ORD-06: Manter a cópia local do catálogo

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Catalog (pelo tópico `catalog.products.v1`) |
| **Escopo** | Commerce (Ordering) |
| **Gatilho** | chega um snapshot de produto no tópico |

## Partes interessadas e interesses

- **Cliente**: pagar o preço vigente e não conseguir comprar um produto descontinuado.
- **Tucano**: fechar pedido mesmo com o catálogo fora do ar; o checkout lê só a cópia local.

## Pré-condições

- Nenhuma. Um consumer group novo lê o tópico desde o início e monta a cópia inteira, porque o tópico é compactado e guarda o último estado de cada produto.

## Garantias mínimas

- A cópia nunca volta no tempo: um snapshot com versão igual ou menor que a guardada não muda nada.

## Garantias de sucesso

- `product_snapshots` fica com o nome, o preço, a moeda e o status da versão mais nova de cada produto.

## Cenário principal de sucesso

1. O Catalog publica o estado completo de um produto que mudou.
2. O sistema lê só os campos que o checkout usa e ignora o resto (tolerant reader).
3. O sistema grava o snapshot se a versão for mais nova que a da cópia, numa instrução só.

## Extensões

- 2a. Evento de outro tipo no tópico: o sistema ignora e segue.
- 2b. Tombstone (mensagem sem valor): o sistema ignora, porque o catálogo ainda não apaga produtos.
- 2c. Snapshot ilegível (sem preço, status desconhecido, SKU fora do formato): vai direto para `dlq.commerce.catalog-sync`, sem retry, porque repetir não conserta.
- 3a. Versão igual ou mais velha (entrega repetida ou fora de ordem): nada muda.

## Variações de tecnologia

- O caso de uso não precisa de inbox: o `INSERT ... ON CONFLICT DO UPDATE ... WHERE catalog_version < EXCLUDED.catalog_version` já torna a escrita idempotente e resistente à ordem de chegada.

## No código

- Port `ForSyncingCatalog`, caso de uso `SyncCatalogProduct`, pacote `Commerce\Ordering`. O consumidor Kafka é o adapter `CatalogSnapshotHandler`, rodando no comando `commerce:sync-catalog`.
