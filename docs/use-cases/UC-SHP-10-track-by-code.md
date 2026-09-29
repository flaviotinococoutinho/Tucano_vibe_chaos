# UC-SHP-10: Rastrear pelo código

| | |
|---|---|
| **Nível** | objetivo do usuário |
| **Ator principal** | Cliente (ou quem tiver o código de rastreio), pela loja ou pela plataforma |
| **Escopo** | Logistics (Timeline) |
| **Gatilho** | alguém abre a página de rastreio com o código da etiqueta (`TX` e mais 13 símbolos) |

## Partes interessadas e interesses

- **Cliente**: saber onde a encomenda está e o que aconteceu com ela, sem ligar para ninguém.
- **Tucano**: responder a um volume grande de consultas sem tocar no banco que registra as remessas.
- **Destinatário**: nenhum dado pessoal na página: nem logradouro, nem número, nem quem recebeu.
- **Lojas**: cada loja mostra o rastreio das próprias remessas e de nenhuma outra ([ADR 0031](../adr/0031-a-store-is-a-tenant.md)).

## Pré-condições

- A remessa foi criada (UC-SHP-01) e o projetor já leu o evento dela.

## Garantias mínimas

- A consulta nunca lê o PostgreSQL da logística; lê uma chave no DynamoDB.
- Um evento entregue duas vezes vira um passo só, nos dois read models.
- Pela loja, a resposta nunca conta que um código existe em outra loja.

## Garantias de sucesso

- A página mostra a loja, o status, a transportadora, o município e a UF de destino, e os passos da jornada em ordem, com o hub, a visita e o motivo de uma visita que falhou.

## Cenário principal de sucesso

1. Dois projetores leem cada evento de `logistics.shipments.v2`, cada um no seu grupo de consumo ([ADR 0027](../adr/0027-one-consumer-group-per-read-model.md)).
2. O `logistics.timeline-projector` acrescenta o passo à linha do tempo da remessa no MongoDB, e o `logistics.tracking-pages`, à página do código no DynamoDB, as duas com a loja que o evento diz. Quando o banco de um deles está fora, ele espera pelo banco, e o outro segue.
3. O cliente pede o rastreio dentro da loja, `GET /v1/stores/{loja}/tracking/{código}`.
4. O sistema lê a página pela chave, confere que ela é daquela loja e responde com a loja, o status e os passos.

## Extensões

- 2a. Evento repetido: os dois read models reconhecem o id do evento e não mudam nada.
- 2b. O processo cai depois de gravar num read model e antes do outro: o evento volta, o primeiro diz que já tem e o segundo alcança.
- 2c. Evento de uma remessa de antes das lojas, sem `store`: o passo entra do mesmo jeito, e a página fica sem loja.
- 3a. O código é digitado em minúsculas ou com espaços: o sistema normaliza antes de buscar.
- 3b. A plataforma não sabe de qual loja é o código (a busca da tela inicial): ela pede `GET /v1/tracking/{código}`, que responde a página de qualquer loja com a loja dela em `store`, ou `null` para uma remessa de antes das lojas. É por ela que o BFF descobre para qual loja mandar o cliente.
- 4a. Código desconhecido, ou cuja notícia ainda não chegou ao projetor: `404` em problem details. É o preço da leitura BASE ([ADR 0012](../adr/0012-acid-writes-base-reads.md)): a página fica atrás da remessa pelo tempo da projeção.
- 4b. A página é de outra loja, ou de uma remessa de antes das lojas: o mesmo `404` de um código desconhecido, com o mesmo corpo. O slug da rota não é conferido contra o cadastro das lojas: um slug que não é de loja nenhuma também cai aqui.
- 4c. O DynamoDB não responde: `503` com `Retry-After`, depois de uma tentativa curta, nas duas rotas. Quem espera é uma pessoa, e a resposta diz quando voltar.

## Variações de tecnologia

- Dois read models para dois usos: o documento por remessa no MongoDB (`shipment_timelines`, com validador `$jsonSchema`) para quem opera, e o item por código no DynamoDB (`tracking_lookup`) para a consulta pública por chave, com TTL de 90 dias depois do último passo.
- Não há transação entre os dois. Cada um deduplica pelo id do evento: no MongoDB, o filtro deixa de fora o documento que já tem o evento e o upsert vira chave duplicada; no DynamoDB, a condição `NOT contains(seen, :event)` recusa o que o conjunto `seen` já tem.
- Os eventos de uma remessa usam o id dela como chave no Kafka e caem na mesma partição, então os passos entram na ordem em que aconteceram, sem versão.
- A loja fica no próprio item do DynamoDB (`store`), e a conferência acontece depois da leitura pela chave: continua uma leitura só, sem índice novo. `store` é palavra reservada nas expressões do DynamoDB, por isso o `UpdateItem` usa o apelido `#store`. No MongoDB, o validador aceita a loja só no formato de slug, e o documento de antes das lojas fica sem ela.

## No código

- Pacote `Logistics\Timeline`, separado do `Shipping`: o modelo de escrita não sabe que a página existe. Ports `ForProjectingTimelines` (caso de uso `ProjectTimeline`), `ForUpdatingTrackingPages` (caso de uso `UpdateTrackingPage`) e `ForTrackingShipments` (caso de uso `TrackShipment`, com `track` para a plataforma e `trackInStore` para a loja); os read models são os adapters `MongoTimelines` e `DynamoTrackingViews`. Os projetores são o `TimelineProjector`, no `logistics:project-timelines`, e o `TrackingPageProjector`, no `logistics:update-tracking-pages`, os dois lendo o evento pelo `ShipmentStepNews`, e as duas rotas são o `TrackingController`.
