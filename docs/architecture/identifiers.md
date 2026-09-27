# Identificadores

Uso dois tipos de identificador, cada um com uma função:

| Uso | Formato | Exemplo | Onde |
|---|---|---|---|
| **identidade** de agregados, eventos e linhas | UUIDv7 (RFC 9562) | `01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b` | chaves primárias, `id` dos eventos, chaves do Kafka |
| **número público e rastreável** | Snowflake de 64 bits | pedido `97663530295234560`, rastreio `TX02PQRFBTW5G03` | tela, e-mail, etiqueta, atendimento |

## Por que UUIDv7

| Opção | Problema |
|---|---|
| autoincremento | depende do banco para existir, vaza volume de negócio (`/orders/1042`) e colide ao juntar bases |
| UUIDv4 | aleatório: cada insert cai numa página diferente do índice B-tree, o que causa page split, índice inchado e cache ruim |
| ULID | resolve a ordenação, mas não é padrão IETF e cada linguagem tem sua variação |
| **UUIDv7** | 48 bits de timestamp em milissegundos na frente e aleatoriedade atrás: ordenado no tempo, amigável ao B-tree e padronizado |

O domínio gera o próprio id (`Uuid::uuid7()`) antes de persistir. Assim o agregado já é criado com identidade, o evento pode referenciá-lo na mesma transação e os testes não precisam de banco. O `DEFAULT uuidv7()` do PostgreSQL 18 fica só como rede de segurança.

## Por que Snowflake

O Twitter criou o Snowflake em 2010 para gerar ids de tweets ordenáveis, compactos (cabem num `BIGINT`) e sem coordenação central por id. Uso o mesmo layout:

```text
 0 | 41 bits: ms desde 2026-01-01T00:00:00Z | 5 bits: datacenter | 5 bits: worker | 12 bits: sequência
 ^
 bit de sinal, sempre 0 (cabe em BIGINT com sinal)
```

- **41 bits** de tempo dão cerca de 69 anos a partir da época escolhida.
- **1024 geradores independentes**: 32 datacenters com 32 workers cada.
- **4096 ids por milissegundo** por gerador; se a sequência estourar, o gerador espera o próximo milissegundo.
- Se o relógio andar para trás, o gerador recusa gerar (`ClockMovedBackwards`) em vez de arriscar duplicidade.

Para o rastreio, a vantagem é que o id carrega a própria origem:

```text
TX02PQRFBTW5G03  ->  97663548934766595
  timestamp  2026-09-27T12:00:04.567Z
  datacenter 1
  worker     12 (logistics-order-intake)
  sequência  3
```

Com o código de rastreio em mãos, o atendimento sabe quando e em qual processo a remessa foi criada, sem consultar banco nenhum.

### Formatos de exibição

- **Número do pedido**: decimal, como os ids de tweet (`97663530295234560`).
- **Código de rastreio**: prefixo `TX` + 13 caracteres em Base32 de Crockford, 15 no total. O alfabeto não tem `I`, `L`, `O` nem `U`, o que evita confusão na leitura, e o código cabe bem num Code128 da etiqueta.

### Atribuição de workers

O Twitter usava o ZooKeeper para distribuir os ids de worker. Aqui a atribuição é estática, por variável de ambiente, porque cada container tem um papel fixo:

| Datacenter | Worker | Processo |
|---|---|---|
| 1 | 1 | `commerce` (PHP-FPM) |
| 1 | 2 | `commerce-order-expiry` |
| 1 | 11 | `logistics` (PHP-FPM) |
| 1 | 12 | `logistics-order-intake` |

### O detalhe do PHP-FPM

No FPM, cada requisição roda num processo filho isolado, e o estado em memória é descartado ao fim dela (shared-nothing). Dois filhos atendendo no mesmo milissegundo teriam a mesma sequência. A solução é guardar o contador em **APCu**, memória compartilhada entre os filhos do mesmo pool, com `apcu_inc` atômico por milissegundo. Em processos de longa duração (workers de CLI, Swoole, Node), a sequência fica na memória do próprio processo.

## Como cada banco guarda

| Banco | UUIDv7 | Snowflake |
|---|---|---|
| PostgreSQL 18 | `uuid` (16 bytes), `DEFAULT uuidv7()` | `BIGINT` com `UNIQUE`, fonte de verdade do número do pedido e do código de rastreio |
| MySQL 8.4 | `BINARY(16)`; leitura com `BIN_TO_UUID(id)` sem o swap flag, que só faz sentido para UUIDv1 e quebraria a ordenação do v7 | - |
| MongoDB 8 | `_id` como `UUID` (BSON binário, subtipo 4) | `NumberLong` |
| DynamoDB | string | string com o código de rastreio formatado (`CHAR(15)`: `TX` + 13 caracteres), usada como chave de lookup |
| Kafka | chave da mensagem e `id` do CloudEvent | dentro do payload: número do pedido em decimal e código de rastreio formatado (`CHAR(15)`) |

O código de rastreio é guardado como `BIGINT`. A forma formatada, `CHAR(15)`, só aparece onde uma chave em texto é necessária: chave do DynamoDB, eventos e etiqueta.
