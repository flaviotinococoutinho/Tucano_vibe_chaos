# UC-CAT-04: Descontinuar um produto

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Administrador do catálogo |
| **Escopo** | Catalog |
| **Gatilho** | um produto sai de linha |

## Partes interessadas e interesses

- **Cliente com pedido antigo**: continuar vendo o produto que comprou.
- **Tucano**: parar de vender o produto sem apagar nada.

## Pré-condições

- O produto está `active`.

## Garantias mínimas

- Nada é apagado: pedidos antigos continuam apontando para o produto.

## Garantias de sucesso

- Produto `discontinued`, fora da vitrine mas acessível pela SKU, e o snapshot novo no tópico faz o checkout recusar o produto.

## Cenário principal de sucesso

1. O administrador descontinua o produto.
2. O sistema muda o estado, sobe a versão, invalida o cache e publica o snapshot.
3. O commerce atualiza a cópia, e um pedido novo com a SKU recebe `409`.

## Extensões

- 1a. O produto não está ativo: `409`.
- 3a. O produto volta a ser vendido: o administrador ativa de novo (UC-CAT-02, passo 3).

## No código

- `ProductController@discontinue`, `ProductService::discontinue()`, a tabela de transições de `ProductStatus`.
