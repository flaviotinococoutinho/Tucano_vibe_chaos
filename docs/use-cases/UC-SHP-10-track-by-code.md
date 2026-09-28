# UC-SHP-10: Rastrear pelo código

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente (ou quem tiver o código de rastreio) |
| **Escopo** | Logistics (Timeline) |
| **Gatilho** | alguém abre a página de rastreio com o código da etiqueta (`TX` e mais 13 símbolos) |

## Partes interessadas e interesses

- **Cliente**: saber onde a encomenda está e o que aconteceu com ela, sem ligar para ninguém.
- **Tucano**: responder a um volume grande de consultas sem tocar no banco que registra as remessas.
- **Destinatário**: nenhum dado pessoal na página: nem logradouro, nem número, nem quem recebeu.

## Pré-condições

- A remessa foi criada (UC-SHP-01) e o projetor já leu o evento dela.

## Garantias mínimas

- A consulta nunca lê o PostgreSQL da logística; lê uma chave no DynamoDB.
- Um evento entregue duas vezes vira um passo só, nos dois read models.

## Garantias de sucesso

- A página mostra o status, a transportadora, o município e a UF de destino, e os passos da jornada em ordem, com o hub, a visita e o motivo de uma visita que falhou.

## Cenário principal de sucesso

1. O projetor (`logistics.timeline-projector`) lê cada evento de `logistics.shipments.v2`.
2. O projetor acrescenta o passo à linha do tempo da remessa no MongoDB e à página do código no DynamoDB.
3. O cliente pede `GET /v1/tracking/{código}`.
4. O sistema lê a página pela chave e responde com o status e os passos.

## Extensões

- 2a. Evento repetido: os dois read models reconhecem o id do evento e não mudam nada.
- 2b. O processo cai depois de gravar num read model e antes do outro: o evento volta, o primeiro diz que já tem e o segundo alcança.
- 3a. O código é digitado em minúsculas ou com espaços: o sistema normaliza antes de buscar.
- 4a. Código desconhecido, ou cuja notícia ainda não chegou ao projetor: `404` em problem details. É o preço da leitura BASE ([ADR 0012](../adr/0012-acid-writes-base-reads.md)): a página fica atrás da remessa pelo tempo da projeção.

## Variações de tecnologia

- Dois read models para dois usos: o documento por remessa no MongoDB (`shipment_timelines`, com validador `$jsonSchema`) para quem opera, e o item por código no DynamoDB (`tracking_lookup`) para a consulta pública por chave, com TTL de 90 dias depois do último passo.
- Não há transação entre os dois. Cada um deduplica pelo id do evento: no MongoDB, o filtro deixa de fora o documento que já tem o evento e o upsert vira chave duplicada; no DynamoDB, a condição `NOT contains(seen, :event)` recusa o que o conjunto `seen` já tem.
- Os eventos de uma remessa usam o id dela como chave no Kafka e caem na mesma partição, então os passos entram na ordem em que aconteceram, sem versão.

## No código

- Pacote `Logistics\Timeline`, separado do `Shipping`: o modelo de escrita não sabe que a página existe. Ports `ForProjectingTimelines` (caso de uso `ProjectTimeline`) e `ForTrackingShipments` (caso de uso `TrackShipment`); os read models são os adapters `MongoTimelines` e `DynamoTrackingViews`. O projetor é o `TimelineProjector`, no `logistics:project-timelines`, e a página é o `TrackingController`.
