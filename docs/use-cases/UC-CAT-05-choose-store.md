# UC-CAT-05: Escolher uma loja

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Cliente |
| **Escopo** | Catalog |
| **Gatilho** | o cliente abre a tela inicial da plataforma, ou chega pelo endereço de uma loja |

## Partes interessadas e interesses

- **Cliente**: saber que lojas existem e entrar na que vende o que procura.
- **Loja**: aparecer com o próprio nome, a própria frase e a própria paleta.
- **Tucano**: abrir uma loja nova com um cadastro no catálogo e um serviço no Kong, sem mudar código ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)).

## Pré-condições

- Nenhuma.

## Garantias mínimas

- O slug de uma loja nunca muda: ele é o endereço dela e a loja de todo evento.
- A paleta é sempre uma das do design system da web, nunca uma cor livre vinda do banco.

## Garantias de sucesso

- O cliente vê as lojas por nome e entra numa delas. Dali em diante, toda tela é daquela loja (UC-CAT-01).

## Cenário principal de sucesso

1. O BFF pede ao sistema as lojas da plataforma, para montar a tela inicial.
2. O sistema responde as lojas por nome, cada uma com slug, nome, frase e paleta.
3. O cliente escolhe uma loja.
4. O BFF pede ao sistema a loja pelo slug e a guarda por 60 s.
5. O sistema responde a loja, e as telas dela saem com o nome e a paleta dela.

## Extensões

- 4a. Slug fora do formato: o roteador responde `404` sem tocar no banco.
- 5a. Loja desconhecida: o sistema responde `404` (`Store tucano does not exist.`), e o BFF avisa que não encontrou a loja.

## Variações de tecnologia

- As lojas são lidas direto do MySQL, sem cache: a tabela tem poucas linhas, achadas por índice único, e o BFF já guarda cada loja por 60 s. A leitura de um produto da loja nem pergunta pela loja (UC-CAT-01, passo 3).
- Não existe API de escrita para lojas: o `StoreSeeder` abre uma loja, renomeia ou troca a frase e a paleta.

## No código

- `StoreController@index` e `@show`, `StoreService`, `StoreRepository`, `StoreSeeder`.
