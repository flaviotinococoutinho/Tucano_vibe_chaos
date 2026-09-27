# UC-CAT-03: Alterar o preço de um produto

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Administrador do catálogo |
| **Escopo** | Catalog |
| **Gatilho** | o preço de um produto muda |

## Partes interessadas e interesses

- **Administrador**: não sobrescrever sem querer a alteração que outra pessoa acabou de fazer.
- **Cliente**: pagar o preço vigente no momento do pedido.

## Pré-condições

- O produto existe.

## Garantias mínimas

- Duas alterações simultâneas nunca se sobrescrevem: uma delas recebe `409`.
- Um consumidor nunca volta para um preço mais velho que o que já tem.

## Garantias de sucesso

- Preço novo, versão nova, cache invalidado e, para produto publicado, snapshot novo no tópico.

## Cenário principal de sucesso

1. O administrador lê o produto e guarda o `ETag` (a versão).
2. O administrador envia o preço novo com `If-Match` e a versão lida.
3. O sistema grava com `WHERE version = <versão>` e sobe a versão.
4. O sistema invalida o cache e publica o snapshot.
5. O commerce atualiza a cópia local (UC-ORD-06), e os pedidos seguintes saem com o preço novo.

## Extensões

- 2a. Sem `If-Match`: o sistema usa a versão que leu no começo do request; a proteção contra escrita simultânea continua.
- 3a. A versão mudou: `409`; o administrador relê e tenta de novo.
- 3b. O corpo não muda nada: o sistema devolve o produto como está, sem versão nova e sem evento.

## Variações de tecnologia

- O RFC 9110 pede `412` para `If-Match` que falha. Uso `409`, porque é o mesmo conflito de versão do `UPDATE` e o erro de domínio vira status pela categoria.

## No código

- `ProductController@update`, `ProductService::change()`, `ProductRepository::update()`.
