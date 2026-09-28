# 0027. Um grupo de consumo por read model

- Status: aceito
- Data: 2026-09-28

## Contexto

O rastreio (UC-SHP-10) tem dois read models alimentados pelo mesmo tópico, `logistics.shipments.v2`: a linha do tempo interna de cada remessa, no MongoDB, e a página pública de rastreio, no DynamoDB. Um projetor só, no grupo `logistics.timeline-projector`, escrevia os dois, um depois do outro: primeiro o MongoDB, depois o DynamoDB.

O experimento `tracking-without-the-timeline` cortou o MongoDB e comprou uma caneca durante a queda. A página pública não chegou a "entregue" em 90 s. Olhando por dentro:

- sem o MongoDB, cada tentativa esperava 2 s pela seleção de servidor, as cinco tentativas do retry acabavam em poucos segundos, e a mensagem ia para a DLQ;
- a escrita no DynamoDB vinha depois da escrita no MongoDB, então ela nunca acontecia;
- a DLQ ficou com a jornada inteira da encomenda (criada, pronta para coleta, coletada, saiu para entrega, entregue), e a página pública dela ficou sem nenhum passo.

Uma queda num banco que a página nem usa apagou a página. Pôr cada leitura no banco que faz aquilo bem ([ADR 0012](0012-acid-writes-base-reads.md)) perde o sentido se um banco de leitura derruba o outro.

## Decisão

- Cada read model lê os eventos no seu próprio grupo de consumo, com o seu caso de uso e o seu worker:
  - `logistics.timeline-projector` (`logistics:project-timelines`, caso de uso `ProjectTimeline`) mantém a linha do tempo no MongoDB;
  - `logistics.tracking-pages` (`logistics:update-tracking-pages`, caso de uso `UpdateTrackingPage`) mantém a página pública no DynamoDB.
- Os dois leem o evento do mesmo jeito, pelo `ShipmentStepNews`, e cada um deduplica pelo id do evento, como antes.
- Cada grupo espera sem limite quando a sua loja está fora de alcance, em vez de mandar mensagens boas para a DLQ: o MongoDB sem servidor para selecionar ou sem conexão, e o DynamoDB sem resposta nenhuma. É o raciocínio do [ADR 0017](0017-wait-for-the-database-not-the-dlq.md), que valia só para o PostgreSQL, estendido às lojas dos read models (`StoreOutOfReach`).

## Consequências

- Com o MongoDB fora, a página pública segue a encomenda como se nada tivesse acontecido: no experimento, "entregue" em 14,6 s, com todos os passos, contra 14,7 s sem falha nenhuma. A linha do tempo interna espera e alcança quando o MongoDB volta.
- A queda de uma loja para só a partição do seu grupo. Cada read model anda no seu ritmo, e um atraso num não vira atraso no outro.
- O Kafka já é um log com leituras independentes, então separar custou um grupo a mais, não um tópico a mais.
- Um grupo novo começa do começo do tópico. Foi assim que o `logistics.tracking-pages`, ao subir, refez sozinho a página que a queda tinha apagado. A contrapartida é que ele relê a retenção inteira do tópico na primeira subida, o que a deduplicação torna inofensivo.
- Um worker a mais, com o seu limite de 128 MB de memória.
- As cinco mensagens que foram para a DLQ antes desta mudança continuam lá. A página daquela encomenda voltou pelo grupo novo; a linha do tempo interna dela só volta com um replay da DLQ.

## Alternativas consideradas

- **Só esperar sem limite, com um projetor**: nenhuma mensagem boa iria para a DLQ, mas a página pública pararia inteira durante qualquer queda do MongoDB, porque as duas escritas estão na mesma fila.
- **Escrever primeiro no DynamoDB**: troca o problema de lugar. Uma queda do DynamoDB passaria a parar a linha do tempo interna.
- **Uma transação entre as duas lojas**: MongoDB e DynamoDB não participam de uma transação comum, e o [ADR 0012](0012-acid-writes-base-reads.md) já aceitou que cada read model é eventual.
- **Um tópico por read model, publicado pela outbox**: daria o mesmo isolamento com mais peças. O log já permite leituras independentes do mesmo evento.
