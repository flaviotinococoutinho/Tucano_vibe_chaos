# UC-CAT-02: Publicar um produto

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Administrador do catálogo |
| **Escopo** | Catalog |
| **Gatilho** | um produto novo vai entrar à venda numa loja |

## Partes interessadas e interesses

- **Administrador**: preparar o produto com calma e só então colocá-lo à venda.
- **Loja**: vender o produto só na própria vitrine ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)).
- **Commerce e Logistics**: receber o produto completo (loja, preço, peso e dimensões) sem chamar a API do catálogo.

## Pré-condições

- A loja e a categoria existem.

## Garantias mínimas

- Rascunho não sai do catálogo: não aparece em vitrine nenhuma e não vai para o Kafka.
- O produto nasce numa loja e nunca muda dela.

## Garantias de sucesso

- Produto `active`, visível na vitrine da loja, e um snapshot com o estado completo, inclusive a loja, em `catalog.products.v1`.

## Cenário principal de sucesso

1. O administrador cria o produto com SKU, nome, loja, categoria, preço, peso e dimensões.
2. O sistema grava o rascunho (versão 1) e responde `201` com `Location`.
3. O administrador ativa o produto.
4. O sistema grava o novo estado, invalida o cache e publica o snapshot depois do commit.

## Extensões

- 1a. SKU já usada, em qualquer loja: `409`. A SKU é única na plataforma.
- 1b. Sem loja, ou com uma loja que não existe: `422` no campo `store`.
- 1c. Categoria desconhecida ou dados inválidos: `422` com os erros por campo.
- 4a. O Kafka falha depois do commit: o produto continua ativo, o log ganha um warning com a versão que não saiu, e o `catalog:republish` fecha a diferença.

## Variações de tecnologia

- Dual write consciente, sem outbox ([ADR 0008](../adr/0008-transactional-outbox.md)).

## No código

- `ProductController@store` e `@activate`, `ProductService::create()` e `::activate()`, `ProductPublisher`, `UnknownStore`.
