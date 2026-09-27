# 0007. UUIDv7 para identidade e Snowflake para números rastreáveis

- Status: aceito
- Data: 2026-09-27

## Contexto

O projeto precisa de identificadores gerados sem coordenação (vários serviços, vários processos), amigáveis a índices B-tree e, no que o cliente vê, curtos e fáceis de rastrear.

## Decisão

- **Identidade** (chaves primárias, ids de evento, chaves do Kafka): UUIDv7 (RFC 9562), gerado pelo domínio antes de persistir.
- **Números públicos** (número do pedido e código de rastreio): Snowflake de 64 bits no layout do Twitter (41 bits de tempo, 5 de datacenter, 5 de worker e 12 de sequência), com época em 2026-01-01.
- No PHP-FPM, a sequência do Snowflake fica em APCu; em processos de longa duração, na memória do processo. Os workers são atribuídos por variável de ambiente.

Os detalhes estão em [identifiers.md](../architecture/identifiers.md).

## Consequências

- Inserções sequenciais no índice, ao contrário do UUIDv4.
- O código de rastreio mostra quando e onde foi gerado, o que ajuda o atendimento e o debug.
- A atribuição de workers é manual. Dois processos com o mesmo worker podem gerar duplicatas, e a constraint `UNIQUE` no banco é a última defesa.

## Alternativas consideradas

- **Autoincremento**: depende do banco e vaza volume de negócio.
- **UUIDv4**: fragmenta o índice.
- **Biblioteca pronta de Snowflake**: existe (`godruoyi/php-snowflake`), mas o gerador tem menos de cem linhas e é justamente o que eu quero estudar.
