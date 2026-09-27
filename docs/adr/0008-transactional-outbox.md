# 0008. Transactional Outbox, com uma exceção consciente no catálogo

- Status: aceito
- Data: 2026-09-27

## Contexto

Gravar no banco e publicar no Kafka são duas operações em sistemas diferentes (*dual write*). Se a segunda falhar depois da primeira, o estado muda e ninguém fica sabendo; se a ordem for invertida, o evento anuncia algo que não aconteceu.

## Decisão

- **Commerce e logistics** gravam o evento na tabela `outbox_messages` na **mesma transação** do estado. Um processo separado (*relay*) lê em lotes com `FOR UPDATE SKIP LOCKED`, publica com `acks=all` e produtor idempotente e marca como publicado.
- **Consumidores** registram o id do evento numa tabela de *inbox* na mesma transação do efeito, o que os torna idempotentes.
- **Catalog** publica depois do commit, sem outbox. É uma exceção **proposital**: o experimento de caos "Kafka fora durante mudança de preço" mostra o evento perdido na prática. A correção fica disponível no comando `catalog:republish`, que republica o estado atual (o tópico é compactado, então republicar é seguro).

## Consequências

- A entrega passa a ser *at-least-once*, e todo consumidor precisa ser idempotente.
- Há um pequeno atraso entre o commit e a publicação, do tamanho do intervalo do relay.
- Com mais de um relay, a ordem entre mensagens do mesmo agregado pode se inverter. Por isso roda um relay por serviço, e a alternativa está documentada.

## Alternativas consideradas

- **CDC com Debezium** lendo o WAL: elimina o *polling*, mas adiciona Kafka Connect e mais memória.
- **Transações do Kafka**: não cobrem o banco relacional.
