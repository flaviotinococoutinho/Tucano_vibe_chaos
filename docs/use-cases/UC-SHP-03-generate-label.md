# UC-SHP-03: Gerar a etiqueta

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Fila de etiquetas (os workers `logistics-label-requests` e `logistics-label-worker`) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | uma remessa é criada: `tucano.logistics.shipment.created` chega em `logistics.shipments.v2` |

## Partes interessadas e interesses

- **Operador logístico**: a etiqueta pronta quando os volumes chegam à doca, no formato da impressora térmica.
- **Transportadora**: um código de barras que o leitor dela entende, com o código de rastreio.
- **Cliente**: a remessa anda sozinha, sem ninguém digitar nada.

## Pré-condições

- A remessa existe e está em `created`.

## Garantias mínimas

- A remessa só sai de `created` com a etiqueta gravada: o guard `LabelMustBeAttached` confere, e o `CHECK label_before_pickup` do banco confere de novo.
- Um job repetido não cria uma segunda etiqueta nem publica um segundo evento: o objeto tem o nome do código de rastreio, e a remessa avança uma vez só.
- Nenhuma linha fica travada enquanto o S3 responde.

## Garantias de sucesso

- Etiqueta em ZPL no bucket `tucano-labels`, em `labels/<código de rastreio>.zpl`; remessa `ready_for_pickup` com a chave do objeto; `ShipmentReadyForPickup` na outbox, na mesma transação da mudança de estado.

## Cenário principal de sucesso

1. O worker `logistics-label-requests` lê o `ShipmentCreated` e põe um job com o id da remessa na fila `label-jobs` (SQS).
2. O worker `logistics-label-worker` recebe o job, e a mensagem fica invisível para os outros workers por 60 s.
3. O sistema lê a remessa e confere que ela ainda está em `created`.
4. O sistema monta a etiqueta em ZPL: transportadora, CD de origem, destinatário, a linha do logradouro (tipo, nome, número e complemento), as divisões dentro do município, o município com a UF, o CEP, o código de barras Code 128 do código de rastreio e os volumes com o peso.
5. O sistema grava a etiqueta no S3, fora de transação.
6. Numa transação, o sistema trava a remessa, move para `ready_for_pickup` com a chave do objeto e grava o histórico e o `ShipmentReadyForPickup` na outbox.
7. O worker apaga a mensagem da fila.

## Extensões

- 1a. O SQS não responde: a partição de `logistics.shipments.v2` espera a fila voltar, e nenhum pedido de etiqueta vai para a DLQ.
- 1b. Evento ilegível: vai direto para `dlq.logistics.label-requests`.
- 3a. A remessa já saiu de `created` (já tem etiqueta ou foi cancelada): nada muda, e o job termina com `not_needed`.
- 3b. A remessa não existe: tentar de novo não resolve, e o job vai direto para `failed_jobs`.
- 5a. O S3 falha, ou a flag `chaos.logistics.label-failure-rate` manda falhar: a remessa continua `created`, e o job volta para a fila em 5 s. Se falhar de novo, volta em 20 s. Na terceira falha, o Laravel registra o job em `failed_jobs`, e o `php artisan queue:retry` o devolve à fila.
- 6a. A remessa foi cancelada enquanto a etiqueta era gravada: a transação encontra `cancelled` e não muda nada; o objeto fica no bucket, sem uso.
- \*a. O worker morre no meio do job e não registra nada: a mensagem volta a ficar visível depois de 60 s, e outro worker a pega. Depois de três entregas sem resposta, o SQS move a mensagem para `label-jobs-dlq` em vez de entregar de novo (redrive policy).

## Variações de tecnologia

- O Kafka é o log e o SQS a fila de trabalho. O pedido de etiqueta parte do evento que a outbox já garantiu, então não existe dual write entre o banco e a fila.
- ZPL é texto, e a impressora desenha o código de barras. O que o cliente digitou passa por `^FH`, para um `^` no nome não virar comando da impressora (injeção de ZPL).
- O job tem timeout de 30 s, abaixo dos 60 s de visibilidade da fila; senão, o SQS entregaria a mesma mensagem a outro worker com o primeiro ainda trabalhando.

## No código

- Ports `ForRequestingLabels` (caso de uso `RequestLabel`, a política que reage ao evento) e `ForGeneratingLabels` (caso de uso `GenerateLabel`), pacote `Logistics\Shipping`. O job é o `GenerateLabelJob`, a impressora é o `ZplLabels` e o bucket é o `S3Labels`. O experimento está no [laboratório da fila de etiquetas](../labs/label-queue.md).
