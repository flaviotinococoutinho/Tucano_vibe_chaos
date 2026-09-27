# 0010. Envelope CloudEvents e contratos versionados

- Status: aceito
- Data: 2026-09-27

## Contexto

Três serviços PHP e dois Node trocam eventos. Sem um envelope comum, cada produtor inventa onde colocar id, tipo, data e correlação, e cada consumidor precisa de um parser diferente.

## Decisão

- Todo evento é um **CloudEvent 1.0** em modo estruturado, com as extensões `correlationid` e `causationid`.
- Os schemas vivem em `contracts/events/` (JSON Schema 2020-12). Produtores e consumidores têm testes de contrato contra esses arquivos.
- Mudança aditiva fica na mesma versão; mudança que quebra vira tópico novo (`.v2`).

## Consequências

- Um padrão aberto, conhecido fora do projeto e suportado por várias ferramentas.
- Os consumidores deduplicam pelo `id` e rastreiam o fluxo pelo `correlationid`.
- JSON é mais verboso que Avro ou Protobuf. Um Schema Registry fica registrado como evolução possível.
