# UC-CAT-01: Consultar o catálogo

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente |
| **Escopo** | Catalog |
| **Gatilho** | o cliente abre a vitrine ou a página de um produto |

## Partes interessadas e interesses

- **Cliente**: ver rápido o preço e a disponibilidade de venda.
- **Tucano**: aguentar picos de leitura sem derrubar o MySQL.

## Pré-condições

- Nenhuma.

## Garantias mínimas

- Rascunho nunca aparece. Redis fora do ar não impede a leitura.

## Garantias de sucesso

- A lista mostra os produtos ativos por nome; a página do produto mostra um ativo ou descontinuado, com `ETag` da versão.

## Cenário principal de sucesso

1. O cliente pede a lista, opcionalmente filtrada por categoria, ou um produto pela SKU.
2. O sistema procura o produto no Redis.
3. O sistema responde com o que achou.

## Extensões

- 2a. O produto não está no Redis: só o request que pega o lock da chave lê o MySQL e grava no Redis; os outros esperam até 500 ms pelo resultado.
- 2b. O Redis falha: o sistema lê direto no MySQL e registra um warning.
- 3a. SKU desconhecida ou rascunho: `404`, e a resposta vazia fica 30 s no cache.
- 1a. SKU fora do formato: o roteador responde `404` sem tocar em banco nem cache.

## Variações de tecnologia

- TTL de 300 s com jitter de 10%, para as chaves não expirarem todas juntas.

## No código

- `ProductController@index` e `@show`, `ProductService::page()` e `::show()`, `ProductCache`.
