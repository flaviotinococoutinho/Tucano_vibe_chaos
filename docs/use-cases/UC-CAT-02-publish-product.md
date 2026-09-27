# UC-CAT-02: Publicar um produto

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Administrador do catálogo |
| **Escopo** | Catalog |
| **Gatilho** | um produto novo vai entrar à venda |

## Partes interessadas e interesses

- **Administrador**: preparar o produto com calma e só então colocá-lo à venda.
- **Commerce e Logistics**: receber o produto completo (preço, peso e dimensões) sem chamar a API do catálogo.

## Pré-condições

- A categoria existe.

## Garantias mínimas

- Rascunho não sai do catálogo: não aparece na vitrine e não vai para o Kafka.

## Garantias de sucesso

- Produto `active`, visível na vitrine, e um snapshot com o estado completo em `catalog.products.v1`.

## Cenário principal de sucesso

1. O administrador cria o produto com SKU, nome, categoria, preço, peso e dimensões.
2. O sistema grava o rascunho (versão 1) e responde `201` com `Location`.
3. O administrador ativa o produto.
4. O sistema grava o novo estado, invalida o cache e publica o snapshot depois do commit.

## Extensões

- 1a. SKU já usada: `409`.
- 1b. Categoria desconhecida ou dados inválidos: `422` com os erros por campo.
- 4a. O Kafka falha depois do commit: o produto continua ativo, o log ganha um warning com a versão que não saiu, e o `catalog:republish` fecha a diferença.

## Variações de tecnologia

- Dual write consciente, sem outbox ([ADR 0008](../adr/0008-transactional-outbox.md)).

## No código

- `ProductController@store` e `@activate`, `ProductService::create()` e `::activate()`, `ProductPublisher`.
