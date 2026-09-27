# UC-SHP-11: Manter peso e dimensões dos produtos

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Catalog (pelo tópico `catalog.products.v1`) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | chega um snapshot de produto no tópico |

## Partes interessadas e interesses

- **Transportadoras**: receber volumes com o peso e o tamanho reais.
- **Tucano**: criar remessas mesmo com o catálogo fora do ar. A logística lê só a cópia local.

## Pré-condições

- Nenhuma. Um consumer group novo lê o tópico desde o início e monta a cópia inteira, porque o tópico é compactado e guarda o último estado de cada produto.

## Garantias mínimas

- A cópia nunca volta no tempo: um snapshot com versão igual ou menor que a guardada não muda nada.

## Garantias de sucesso

- `product_snapshots` fica com o nome, o peso e as dimensões da versão mais nova de cada produto.

## Cenário principal de sucesso

1. O Catalog publica o estado completo de um produto que mudou.
2. O sistema lê só o que a logística usa (SKU, nome, peso, dimensões e versão) e ignora o resto (tolerant reader).
3. O sistema grava o snapshot se a versão for mais nova que a da cópia, numa instrução só.

## Extensões

- 2a. Evento de outro tipo no tópico: o sistema ignora e segue.
- 2b. Tombstone (mensagem sem valor): o sistema ignora, porque o catálogo ainda não apaga produtos.
- 2c. Snapshot ilegível (sem peso, com dimensão zerada ou em texto, com peso que não cabe na coluna `integer`, com `productId` que não é UUID): vai direto para `dlq.logistics.catalog-sync`, sem retry, porque repetir não conserta.
- 3a. Versão igual ou mais velha (entrega repetida ou fora de ordem): nada muda.

## Variações de tecnologia

- O caso de uso não precisa de inbox: o `INSERT ... ON CONFLICT DO UPDATE ... WHERE catalog_version < EXCLUDED.catalog_version` já torna a escrita idempotente e resistente à ordem de chegada.
- Produto descontinuado continua na cópia. A remessa de um pedido pago antes da descontinuação ainda precisa do peso dele.

## No código

- Port `ForSyncingCatalog`, caso de uso `SyncCatalogProduct`, pacote `Logistics\Shipping`. O consumidor Kafka é o adapter `CatalogSnapshotHandler`, rodando no comando `logistics:sync-catalog`.
