# UC-CAT-01: Consultar o catálogo de uma loja

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente |
| **Escopo** | Catalog |
| **Gatilho** | o cliente abre a vitrine de uma loja ou a página de um produto dela |

## Partes interessadas e interesses

- **Cliente**: ver rápido o preço e a disponibilidade de venda.
- **Loja**: mostrar só o que ela vende, sem a vitrine misturada com a das vizinhas ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)).
- **Tucano**: aguentar picos de leitura sem derrubar o MySQL.

## Pré-condições

- O cliente escolheu a loja (UC-CAT-05).

## Garantias mínimas

- Rascunho nunca aparece. Redis fora do ar não impede a leitura.
- Uma loja nunca mostra o produto de outra: ele responde o mesmo `404` de uma SKU que não existe.

## Garantias de sucesso

- A lista mostra os produtos ativos da loja por nome; a página do produto mostra um produto ativo ou descontinuado da loja, com `ETag` da versão.

## Cenário principal de sucesso

1. O cliente pede a lista da loja, opcionalmente filtrada por categoria, ou um produto da loja pela SKU.
2. Na lista, o sistema confere que a loja existe e lê no MySQL a página de produtos ativos dela.
3. No produto, o sistema procura a SKU no Redis e compara a loja que a entrada carrega com a do endereço.
4. O sistema responde com o que achou.

## Extensões

- 1a. SKU ou slug de loja fora do formato: o roteador responde `404` sem tocar em banco nem cache.
- 2a. Loja desconhecida: `404`.
- 2b. Categoria desconhecida: `422` no campo `category`.
- 3a. O produto não está no Redis: só o request que pega o lock da chave lê o MySQL e grava no Redis; os outros esperam até 500 ms pelo resultado. A entrada serve a todas as lojas, porque a chave é a SKU.
- 3b. O Redis falha: o sistema lê direto no MySQL e registra um warning.
- 3c. SKU desconhecida ou rascunho: `404`, e a resposta vazia fica 30 s no cache.
- 3d. O produto é de outra loja: o mesmo `404` de uma SKU desconhecida, com a mesma mensagem. Uma SKU debaixo de uma loja que não existe responde igual, porque a leitura do produto não consulta a loja.

## Variações de tecnologia

- TTL de 300 s com jitter de 10%, para as chaves não expirarem todas juntas.
- As rotas da plataforma (`/v1/products`) continuam para o admin e os laboratórios, e cada produto que elas devolvem diz a loja.

## No código

- `ProductController@indexInStore` e `@showInStore`, `ProductService::storePage()` e `::showInStore()`, `ProductCache`. Na plataforma, `ProductController@index` e `@show`.
