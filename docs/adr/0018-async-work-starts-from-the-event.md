# 0018. Começar o trabalho assíncrono pelo evento, não por um dispatch depois do commit

- Status: aceito
- Data: 2026-09-27

## Contexto

A etiqueta da remessa é trabalho de fila: cada pedido de etiqueta vai para um worker só, volta se o worker morrer e tem um limite de tentativas. É o que o SQS oferece, com visibilidade e redrive para uma DLQ, e o que o Kafka não oferece, já que o Kafka é um log.

O caminho óbvio no Laravel é `dispatch()->afterCommit()` no fim do `CreateShipment`. Só que isso é dual write: a transação confirma, o processo morre antes do dispatch, e a remessa fica em `created` para sempre. A saída seria uma varredura periódica procurando remessas sem etiqueta.

## Decisão

- O `CreateShipment` continua gravando só o que já gravava: a remessa e o `ShipmentCreated` na outbox, na mesma transação.
- Uma ponte, o consumer group `logistics.label-requests`, lê o `ShipmentCreated` de `logistics.shipments.v1` e põe o job na fila `label-jobs`.
- O job é idempotente: a chave do objeto é o código de rastreio, e a remessa só avança se ainda estiver em `created`.

## Consequências

- Não existe dual write: o pedido de etiqueta vem de um evento que a outbox já garantiu.
- O Kafka vira a última rede de segurança. No laboratório, jobs perdidos pelo SQS e pelo `failed_jobs` voltaram com um replay do consumer group.
- São um salto e um worker a mais, e cerca de um segundo a mais até a etiqueta.
- O job pode chegar do retry do Laravel, do redrive do SQS ou do replay do Kafka, e por isso precisa ser idempotente.

## Alternativas consideradas

- **`dispatch()->afterCommit()` com varredura periódica**: funciona, mas a varredura é mais uma peça, e a janela entre o commit e o dispatch continua existindo.
- **Fila no próprio banco (driver `database` do Laravel)**: o job entraria na mesma transação, mas o laboratório perderia o SQS, que é o que eu quero praticar.
- **Worker da etiqueta consumindo o Kafka direto**: um salto a menos, mas sem visibilidade, redrive e competição entre workers, que são a razão de existir de uma fila de trabalho.
