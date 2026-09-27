# Identificadores

Dois tipos de identificador, cada um com um trabalho:

| Uso | Formato | Exemplo | Onde |
|---|---|---|---|
| **identidade** de agregados, eventos e linhas | UUIDv7 (RFC 9562) | `01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b` | chaves primárias, `id` dos eventos, chaves do Kafka |
| **número público e rastreável** | Snowflake de 64 bits | pedido `97663530295234560`, rastreio `TX02PQRFBTW5G03` | tela, e-mail, etiqueta, atendimento |

## Por que UUIDv7

| Opção | Problema |
|---|---|
| auto-incremento | depende do banco para existir, vaza volume de negócio (`/orders/1042`) e colide ao juntar bases |
| UUIDv4 | aleatório: cada insert cai numa página diferente do índice B-tree, o que causa *page splits*, índices inchados e cache ruim |
| ULID | resolve a ordenação, mas não é padrão IETF e cada linguagem tem sua variação |
| **UUIDv7** | 48 bits de timestamp em milissegundos na frente e aleatoriedade atrás: ordenado no tempo, amigável ao B-tree e padronizado |

O domínio gera o próprio id (`Uuid::uuid7()`) **antes** de persistir. Assim o agregado nasce com identidade, o evento pode referenciá-lo na mesma transação e testes não precisam de banco. O `DEFAULT uuidv7()` do PostgreSQL 18 fica só como rede de segurança.

## Por que Snowflake

O Twitter criou o Snowflake em 2010 para gerar ids de tweets ordenáveis, compactos (cabem num `BIGINT`) e sem coordenação central por id. O mesmo layout é usado aqui:

```text
 0 | 41 bits: ms desde 2026-01-01T00:00:00Z | 5 bits: datacenter | 5 bits: worker | 12 bits: sequência
 ↑
 bit de sinal, sempre 0 (cabe em BIGINT com sinal)
```

- **41 bits** de tempo dão cerca de 69 anos a partir da época escolhida.
- **32 datacenters × 32 workers** = 1024 geradores independentes.
- **4096 ids por milissegundo** por gerador; se a sequência estourar, o gerador espera o próximo milissegundo.
- Se o relógio andar para trás, o gerador recusa gerar (`ClockMovedBackwards`) em vez de arriscar duplicidade.

O ganho para *tracking* é que o id se explica sozinho:

```text
TX02PQRFBTW5G03  →  97663548934766595
  timestamp  2026-09-27T12:00:04.567Z
  datacenter 1
  worker     12 (logistics-order-intake)
  sequência  3
```

Com o código de rastreio em mãos, o atendimento sabe quando e em qual processo a remessa nasceu sem consultar banco nenhum.

### Formatos de exibição

- **Número do pedido**: decimal, como os ids de tweet (`97663530295234560`).
- **Código de rastreio**: prefixo `TX` + Base32 de Crockford com 13 caracteres. Não tem `I`, `L`, `O` nem `U`, o que evita confusão na leitura e cabe bem num Code128 da etiqueta.

### Atribuição de workers

O Twitter usava o ZooKeeper para distribuir os ids de worker. Aqui a atribuição é estática, por variável de ambiente, porque cada container tem um papel fixo:

| Datacenter | Worker | Processo |
|---|---|---|
| 1 | 1 | `commerce` (PHP-FPM) |
| 1 | 2 | `commerce-order-expiry` |
| 1 | 11 | `logistics` (PHP-FPM) |
| 1 | 12 | `logistics-order-intake` |

### O detalhe do PHP-FPM

No FPM, cada requisição roda num processo filho isolado e o estado em memória morre ao fim dela (*shared-nothing*). Dois filhos atendendo no mesmo milissegundo teriam a mesma sequência. A saída é guardar o contador em **APCu**, memória compartilhada entre os filhos do mesmo pool, com `apcu_inc` atômico por milissegundo. Em processos de longa duração (workers de CLI, Swoole, Node) a sequência vive na memória do próprio processo.

## Como cada banco guarda

| Banco | UUIDv7 | Snowflake |
|---|---|---|
| PostgreSQL 18 | `uuid` (16 bytes), `DEFAULT uuidv7()` | `bigint` com `UNIQUE` |
| MySQL 8.4 | `BINARY(16)`; leitura com `BIN_TO_UUID(id)` **sem** o *swap flag*, que só faz sentido para UUIDv1 e bagunçaria a ordem do v7 | — |
| MongoDB 8 | `_id` como `UUID` (BSON binário, subtipo 4) | `NumberLong` |
| DynamoDB | string | string formatada (`TX...`) como chave de lookup |
| Kafka | chave da mensagem e `id` do CloudEvent | dentro do payload |
