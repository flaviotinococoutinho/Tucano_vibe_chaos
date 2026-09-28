# Changelog

Todas as mudanças relevantes ficam registradas aqui. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o versionamento segue o [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Added

- Experimentos de caos como código (ADR 0025): quatro experimentos do Chaos Toolkit em `chaos/experiments`, cada um com o estado estável conferido antes da falha e de novo com ela ativa, e rollbacks que rodam sempre. O PSP lento abre o circuit breaker e pagar passa a responder `503` em 0,10 s; o commerce cortado derruba só as telas dele; o catálogo lento faz o BFF desistir em 5 s; e, sem nenhum webhook do PSP, a conciliação ainda traz o desfecho em pouco mais de um minuto. As sondas compram pela loja seguindo a hipermídia do BFF, e pagam com o cartão recusado para devolver o estoque. `make experiments` lista, `make experiment e=<nome>` roda, e o CI valida cada experimento.

### Fixed

- Com o banco fora do ar, os serviços PHP respondiam `500` com o detalhe escondido, e a web mostrava um erro sem saída. Agora uma conexão recusada ou perdida vira `503` com `Retry-After: 5` e um texto fixo que não revela host nem porta; o BFF repassa o `Retry-After`, e qualquer outro erro de banco continua `500` (ADR 0026).

## [0.8.0] - 2026-09-28

### Added

- O guia do projeto (`docs/guia`), em oito capítulos curtos: a jornada de um pedido e os casos de uso, as stacks e as ferramentas transversais, a natureza da informação, o playground do caos, as abstrações que seguram a entropia, um catálogo de conceitos com ganho, custo e possibilidade de cada um (de Brooks, Parnas e Dijkstra a Fielding, Helland e Nygard), os padrões e RFCs, e o roteiro para apresentar o projeto. O README foi reescrito a partir dele, com as telas da loja.
- A web da Tucano (`services/web`), em React 19 e TypeScript com Vite: um intérprete das telas Siren do BFF, sem nenhuma URL montada à mão além do prefixo `/bff/v1`. Um registro liga cada classe de tela a um componente, e uma classe nova funciona pelo `GenericScreen` antes de ganhar o seu. Tem tela viva (pausa com a aba escondida), formulários que mostram cada erro do servidor ao lado do campo, o caminho de volta pelos links `collection` e `up`, foco e anúncios para leitor de tela, tema claro e escuro com os tokens da identidade e o banner da marca na home. O nginx serve com CSP, `nosniff` e cache imutável para os arquivos com hash, e o Kong a publica em `/`.
- Dado sensível passa por um proxy (ADR 0024): o `Sensitive` do shared kernel guarda nome, e-mail, documento e token de cartão e se imprime mascarado (`A*** S***`, `a***@example.com`, `***09`, `tok_***`) em log, erro, dump e JSON; o valor sai só pelo `reveal()`, e a `serialize()` o recusa. O `DataCategory` é o enum rico das categorias, que sabem se mascarar e a que regra respondem (LGPD ou PCI DSS). No commerce, `PersonName`, `EmailAddress` e `CardToken` usam o proxy; na logistics, `Recipient` e `ProofOfDelivery`. A fitness function `SensitiveDataLeavesOnPurposeTest` falha quando um `reveal()` aparece fora dos adapters, dos eventos e das exceções com motivo escrito.
- O pedido guarda o código de rastreio da remessa (`orders.tracking_code`, `CHAR(15)`), aprendido na coleta (UC-ORD-04), e a consulta do pedido devolve `trackingCode`, `null` até a coleta (UC-ORD-05). É o que deixa a web ir do pedido direto ao rastreio. Dois `CHECK`s guardam a regra no banco: o formato, e só pedido que saiu tem código.
- O pedido cancelado guarda o motivo (`orders.cancellation_reason`), e a consulta devolve `cancellationReason`. A migration copia o motivo do histórico para os pedidos que já estavam cancelados, e o banco cobra a regra nos dois sentidos: todo cancelado tem motivo, e só cancelado tem.
- O BFF monta as telas da web em Siren (ADR 0023): início, catálogo, produto, checkout, pedido e rastreio, com as palavras em português e os fluxos em links e ações. O pedido se atualiza sozinho enquanto o pagamento confirma e enquanto a encomenda anda, e termina contando o que aconteceu (cartão recusado, prazo vencido, devolução). Cada formulário que muda algo leva a sua `Idempotency-Key`, e o checkout dá ao navegador um cliente convidado no cookie `tucano_guest`.
- O BFF fala com cada serviço pelo seu proxy no Toxiproxy (`bff-catalog`, `bff-commerce` e `bff-logistics`) e com prazo (`UPSTREAM_TIMEOUT_MS`). Serviço fora do ar ou lento vira 503 com `Retry-After` só nas telas que dependem dele. Variáveis novas: `CATALOG_URL`, `COMMERCE_URL`, `LOGISTICS_URL` e `UPSTREAM_TIMEOUT_MS`.
- Os exemplos do contrato do BFF ganharam o pedido recém-pago e o cancelado por cartão recusado, e os testes do BFF montam cada exemplo com a mesma função que responde a web.

### Changed

- A tela do pedido mantém o aviso "Pagamento aprovado." enquanto o pedido é preparado, e não só na primeira atualização, e deixa de mostrar a validade da reserva depois que o pedido sai de "Aguardando pagamento".
- A estratégia de reserva do laboratório vem da preferência `Prefer: reservation-strategy=<nome>` (RFC 7240) no lugar do header `X-Inventory-Strategy` (RFC 6648). A resposta confirma com `Preference-Applied` quando segue a preferência; um nome que não existe deixou de ser 400 e passou a ser uma dica ignorada.
- Os problemas que os clientes precisam distinguir ganharam tipo (RFC 9457): `stock-not-reserved`, `product-unavailable`, `order-not-payable` e `idempotency-key-reused`, cada um com a sua seção em `contracts/http/problems.md`. O erro de domínio declara o nome com `#[ProblemType]`, do shared kernel. O BFF lê o tipo e diz a coisa certa: produto fora de linha, estoque que acabou ou formulário já usado deixaram de virar a mesma frase.
- As três cópias do `ProblemDetails` (catalog, commerce e logistics) seguem a mesma regra: só o erro inesperado esconde o `detail`; um 503 deliberado mostra o que fazer. As cópias do commerce e da logistics ficaram idênticas, e o CI compara as duas.
- A leitura pública do pedido (`GET /v1/orders/{id}` e a resposta de `POST /v1/orders`) mostra o cliente mascarado: sem login, quem tem o id do pedido vê o pedido, não quem comprou.
- A plataforma Node (a cópia do bff e do partners-sim) deixa um `DomainError` levar `retryAfterSeconds`, que vira o header `Retry-After`, e mensagens por campo, que viram `errors`. Um 503 de domínio passa a mostrar o `detail`, como os serviços PHP já faziam; só o erro inesperado esconde o detalhe.
- O contrato do BFF: `orderNumber` é o texto decimal do Snowflake do commerce, o rastreio ganhou `carrierLabel` e fica vivo enquanto a encomenda anda, o produto fora de linha vem sem `buy` e com aviso, o campo do código de rastreio aceita o que as pessoas digitam, e os problemas criados pelo BFF falam português no `detail`.
- No context map, o BFF deixou de ser Conformist: ele lê cada serviço por uma camada anticorrupção (`src/upstream/`).
- Fornecedor mora só nos adapters (ADR 0022): o Deptrac ganhou a camada `Vendor` e tirou da aplicação a licença de usar feature flags. Os eventos de domínio pegam o id do `EventId` do shared kernel, e o `ChooseCarrier` pergunta o `DispatchMode` (enum rico) a um port, em vez de ler a flag.
- Pacotes só se encontram pelas fachadas: o teste `PackagesMeetThroughTheirFacadesTest` cobra que o núcleo de um pacote não conheça outro, e o Ordering declara que reserva estoque pelo nome `reserves-stock`, em vez de importar o middleware do Inventory.

### Fixed

- O Kong pergunta registro A antes de SRV (`KONG_DNS_ORDER`). O DNS do Docker não conhece SRV e encaminhava a pergunta para a internet, onde `web` é um domínio de topo: a ICANN responde uma colisão de nome com `127.0.53.53`, e toda chamada à web dava 502.

### Security

- As mensagens de erro deixaram de repetir o valor recebido: um e-mail inválido não volta no `detail`, e um número de cartão mandado no lugar do token não volta na resposta nem vai para o log (PCI DSS). Um teste agora cobra que o número não aparece em lugar nenhum da resposta.

## [0.7.0] - 2026-09-28

### Added

- Rastreio pelo código (UC-SHP-10), o lado de leitura das remessas no pacote `Timeline`: o projetor `logistics-timeline-projector` leva cada evento de `logistics.shipments.v2` para a linha do tempo no MongoDB e para a página pública no DynamoDB, cada um deduplicando pelo id do evento, e `GET /v1/tracking/{código}` lê a página por chave.
- Vigia das jornadas paradas (UC-SHP-13): o worker `logistics-stalled-journeys-watch` faz, a cada 15 min, uma leitura analítica (transação `READ ONLY` com `statement_timeout`) das remessas com a transportadora e sem passo há mais de uma hora, e manda o alerta no log, no nível `alert`, e por e-mail (Mailpit, pelo Toxiproxy). Cada linha traz o motivo, pela última rodada da conciliação, e o mesmo alerta só se repete depois de 4 h (`Cache::add` no Redis). `make stalled` roda uma rodada na hora.
- Referência de configuração (`docs/operations/configuration.md`) com as regras dos nomes e cada variável de cada serviço, e a fitness function `make config-check`, que também roda no CI (ADR 0021).
- Variáveis novas para o que estava fixo no código: pausas dos workers, retries e poll dos consumers, lote e pausas do relay da outbox, timeouts dos clientes (transportadora, PSP, S3, SQS, DynamoDB, flagd, SMTP), tentativas do job de etiqueta, cache e página do catálogo, porta e espera do tracking, retries e retenção do partners-sim.
- Setup com Ansible (`infra/ansible`, `make setup` e `make setup-check`): confere disco e memória, instala o que falta no macOS (Homebrew e Colima) ou no Debian e Ubuntu (Docker Engine), liga a VM, escreve o `.env` e sobe a stack até os health checks passarem. O CI roda o `ansible-lint` e a parte da máquina num Ubuntu limpo, e o workflow manual `setup` roda o playbook inteiro num runner limpo.
- `make trim`, que devolve ao Mac o espaço liberado dentro da VM do Colima.

### Changed

- Nomes de variáveis que mudaram (quem tem override precisa trocar): `PAYMENT_RECONCILIATION_QUIET_SECONDS` virou `PAYMENTS_RECONCILIATION_QUIET_SECONDS`, `ORDER_RESERVATION_MINUTES` virou `ORDERS_RESERVATION_MINUTES`, `PAYFAKE_CIRCUIT_FAILURES` virou `PAYFAKE_CIRCUIT_FAILURE_THRESHOLD`, `CARRIERS_RECONCILIATION_QUIET_SECONDS` virou `JOURNEYS_RECONCILIATION_QUIET_SECONDS`, `TRACKING_KEEP_DAYS` virou `TRACKING_PAGE_RETENTION_DAYS`, `KAFKA_MESSAGE_TIMEOUT_MS` virou `KAFKA_PRODUCER_MESSAGE_TIMEOUT_MS`, `DB_CONNECT_TIMEOUT` e `DB_READ_TIMEOUT` ganharam `_SECONDS`, `REDIS_TIMEOUT` e `REDIS_READ_TIMEOUT` viraram `_MS`, e `SERVICE_NAME` virou `APP_NAME` no bff e no partners-sim. `SQS_QUEUE` saiu: a fila das etiquetas é `LABELS_QUEUE`.
- Os `config/*.php` do commerce e da logistics ficaram só com o que os serviços usam; `filesystems`, `mail`, `queue` e `services` saíram onde nada os usava.
- A conciliação da jornada grava o resultado de cada rodada em `journey_checks`, e o histórico vazio na transportadora virou `unknown_to_carrier`, separado do `up_to_date` de uma jornada que só está lenta.
- Uma cobrança que o PSP recebeu e não mostra mais ganha uma janela configurável (`PAYMENTS_RECONCILIATION_LOST_CHARGE_AFTER_SECONDS`, uma hora por padrão); fechada a janela, o pagamento falha com `charge_lost` em vez de pedir uma pessoa a cada rodada.

### Fixed

- O `make up` agora constrói e depois sobe sem `--build`: o `up --build` recriava todos os serviços a cada chamada, mesmo sem mudança nenhuma, porque o digest que o compose guarda muda a cada build, até com cache. Uma stack igual fica como está.
- O Kong pergunta de novo ao DNS a cada 5 s (`KONG_DNS_VALID_TTL`): com o TTL de 600 s do Docker, um serviço recriado pelo compose ficava 502 atrás do gateway por até dez minutos.
- O `make doctor` agora olha o disco do Mac, e não só o da VM: o disco da VM cresce dentro do disco do Mac, e um Mac cheio fez a VM remontar o disco do Docker como somente leitura no meio de um rebuild.
- O README da logística agora lista o webhook das transportadoras entre os endpoints.

## [0.6.0] - 2026-09-27

### Added

- Conciliação da jornada com a transportadora (UC-SHP-12): o worker `logistics-journey-reconciler` pega a remessa que passa 60 s sem notícia (lease pelo `updated_at` com `SKIP LOCKED` e índice parcial), lê o histórico da coleta na transportadora e aplica em ordem os passos que faltam, pelos mesmos casos de uso do webhook.
- `GET /carriers/v1/pickups/{id}/events` na CarrierFake: o histórico de rastreio, com os eventos cujo webhook o caos derrubou.
- Laboratório dos webhooks perdidos, com a leva de 12 pedidos que travou 10 remessas e a conciliação que recuperou todas.
- O pedido acompanha a remessa (UC-ORD-04): o consumer group `commerce.shipment-sync` lê `logistics.shipments.v2`, e a coleta leva o pedido para `shipped`, a entrega para `delivered` e a devolução para `returned`, com `order.shipped`, `order.delivered` e `order.returned` na outbox e contrato em JSON Schema. Na devolução, o pagamento capturado fica `refund_requested` na mesma transação, e a conciliação manda o estorno ao PSP.

### Changed

- O webhook da transportadora e o histórico dela passam pelo mesmo tradutor (`CarrierFakeEvents`) e chegam aos casos de uso como `CarrierEvent`, com um construtor nomeado por passo.

### Fixed

- Um hub scan que chegava depois da saída para entrega era recusado para sempre, e na conciliação travava a remessa antes da entrega. Agora ele é `obsolete`: fica na inbox e não move nada.

## [0.5.0] - 2026-09-27

### Added

- Etiqueta da remessa (UC-SHP-03): a ponte `logistics-label-requests` transforma cada `ShipmentCreated` em job na fila `label-jobs` do SQS, e o worker gera a etiqueta em ZPL (com escape contra injeção de comandos da impressora), grava no bucket `tucano-labels` e move a remessa para `ready_for_pickup`, com `ShipmentReadyForPickup` na outbox e contrato em JSON Schema. O SDK da AWS entra podado para S3 e SQS.
- `make flag` e `make flag-reset` trocam a variante de uma flag na cópia que o flagd observa, sem reiniciar nada.
- Laboratório da fila de etiquetas, com a flag `chaos.logistics.label-failure-rate`, os retries do job, o `failed_jobs` e o replay do Kafka.
- CarrierFake no `partners-sim`: as transportadoras do laboratório (frota própria e parceiros), com agendamento de coleta idempotente, a jornada da encomenda num relógio comprimido (coleta, hubs, saída para entrega, até três visitas, devolução), webhooks assinados em ordem, caos em tempo real e contrato OpenAPI. O envio de webhooks, a assinatura e as chaves de idempotência viraram módulos comuns ao PayFake e ao CarrierFake.
- A jornada da remessa até a porta (UC-SHP-04 a 08): a coleta agendada na transportadora quando a etiqueta fica pronta, e os webhooks assinados da transportadora aplicados na máquina de estados, com inbox, o comprovante e o motivo de cada visita em `delivery_attempts` e os sete eventos novos com contrato em JSON Schema.
- O endereço no shared kernel ([ADR 0020](docs/adr/0020-address-by-thoroughfare-and-divisions.md)): logradouro com tipo e nome, número em texto (`KM 500`, `S/N`), divisões territoriais da UF ao bairro com o geocódigo do IBGE conferido por prefixo, CEP e coordenadas.

### Changed

- O verificador da assinatura de webhook (`t=...,v1=...`) mora no pacote de mensageria, e o commerce e a logística usam o mesmo.
- O commerce e a logística usam o endereço do shared kernel. O checkout recebe logradouro, número em texto e divisões; `orders` e `shipments` trocam `street`, `district`, `city` e `state` por tipo e nome do logradouro e pelas divisões em `jsonb` com CHECK, e o número passa a `VARCHAR(20)`. A migração separa o `street` gravado em tipo e nome. A etiqueta imprime a linha do logradouro e as divisões dentro do município, e o `fulfillment_centers.city` da logística virou `municipality`.
- Tópicos `commerce.orders.v2` e `logistics.shipments.v2`, como pede a ADR 0010: o `order.paid` leva o endereço novo, e o `shipment.created` leva o destino até o município. Os consumidores leem `.v1` e `.v2` até o `.v1` esvaziar, e o `order.paid` do `.v1` passa pelo `LegacyShippingAddress`.
- Os value objects dos domínios nascem por construtores nomeados, como mandam as convenções: `Customer::of`, `OrderNumber::fromSnowflake`, `TrackingCode::fromSnowflake`, `ShipmentReference::of`, `Parcel::of`, `StatusTransition::initial` e `between` e `Dimensions::ofMillimetres` no lugar de `new`, e `Money` e `Dimensions` saem por `toArray()`.

### Removed

- A leitura dos tópicos `commerce.orders.v1` e `logistics.shipments.v1`, o `LegacyShippingAddress` e os schemas congelados do `.v1`, depois que o lag dos três consumer groups que liam o `.v1` zerou. O script do Kafka não cria mais os tópicos antigos.

### Fixed

- Os jobs que esgotam as tentativas agora ficam no `failed_jobs` do PostgreSQL. O `config/queue.php` apontava para um SQLite que não existe, e o job que falhava sumia sem rastro.

## [0.4.0] - 2026-09-27

### Added

- Pedidos no commerce: `POST /v1/orders` (UC-ORD-01) com idempotência na mesma transação do pedido, reserva de estoque por CD com savepoint (UC-INV-01), `OrderPlaced` na outbox com contrato em JSON Schema, e `GET /v1/orders/{id}` (UC-ORD-05).
- Workers do commerce: relay da outbox (com pausa por flag de caos) e o consumidor `commerce.catalog-sync`, que mantém a cópia local do catálogo por versão (UC-ORD-06).
- Laboratório de overselling: estratégias de reserva `atomic`, `pessimistic`, `optimistic`, `serializable` e `naive`, escolhidas pela flag `inventory.reservation-strategy` ou, no laboratório, pelo header `X-Inventory-Strategy`, com teste de corrida entre processos.
- Expiração de pedidos não pagos (UC-ORD-03): worker `commerce-order-expiry` com `FOR UPDATE SKIP LOCKED`, liberação do estoque (UC-INV-03), histórico de transições gravado a partir do agregado e `OrderCancelled` na outbox com contrato em JSON Schema.
- Produtos no catálogo (UC-CAT-01 a 04): API `/v1/products`, cache-aside com jitter, cache negativo e lock contra stampede, concorrência otimista com `If-Match`, snapshot no tópico compactado depois do commit (dual write consciente) e `catalog:republish`, que o job de migração roda a cada subida.
- PayFake no `partners-sim`: cobranças com `Idempotency-Key`, ciclo de vida como união discriminada, webhooks assinados (`PayFake-Signature`, HMAC-SHA256 com timestamp) com retry e backoff, estornos, API de caos em tempo real e contrato OpenAPI 3.1 com os webhooks.
- Pagamento de pedidos (UC-PAY-01): `POST /v1/orders/{id}/payments` com idempotência, um pagamento pendente por pedido garantido pelo banco, chamada ao PayFake fora de transação com a camada anticorrupção, e circuit breaker com estado no Redis (`503` com `Retry-After` quando aberto).
- Laboratório de circuit breaker, com o experimento de latência no PSP e os números.
- Webhook do PayFake (UC-PAY-02): assinatura `PayFake-Signature` verificada sobre o corpo cru, inbox para evento repetido e, numa transação só, pagamento capturado ou recusado, pedido pago ou cancelado (UC-ORD-07), reserva convertida em venda (UC-INV-04) e `OrderPaid` na outbox; dinheiro que chega depois da expiração fica marcado para estorno.
- Job `contracts` no CI: os JSON Schemas de eventos são validados contra o metaschema e os contratos HTTP passam pelo lint do Redocly.
- Conciliação de pagamentos (UC-PAY-03) e estorno (UC-PAY-04): worker `commerce-payment-reconciler`, que pergunta ao PayFake pelos pagamentos sem desfecho há 60 s e aplica a resposta pelo mesmo caminho do webhook; estado `abandoned` para a cobrança que nunca chegou ao PSP, com a palavra tardia do PSP ainda aceita; estorno com o id do pagamento como `Idempotency-Key`; e o laboratório com o experimento.
- `GET /payfake/v1/charges?reference=` no PayFake, para achar a cobrança cujo id se perdeu junto com a resposta.
- Remessas na logística: o consumidor `logistics.order-intake` cria a remessa do pedido pago (UC-SHP-01) e cancela a do pedido cancelado (UC-SHP-09), com inbox e outbox na mesma transação; escolha de transportadora por uma corrente de regras (UC-SHP-02); cópia de peso e dimensões do catálogo (UC-SHP-11); a máquina de estados completa com seis guards; e `ShipmentCreated` e `ShipmentCancelled` em `logistics.shipments.v1`, com contrato em JSON Schema.

### Changed

- A leitura de CloudEvents nos consumidores ficou num lugar só: `IncomingEvent` no pacote de mensageria e `EventFields` no shared kernel. Um `time` que não é data agora torna o evento ilegível, e ele vai direto para a DLQ em vez de gastar as tentativas.
- O Ordering não conhece mais os erros do Inventory: o adapter traduz a recusa em `StockNotReserved`, com a mesma mensagem e categoria, como o Shipping já faz com o CarrierSelection.

### Fixed

- Os logs da librdkafka saem pelo logger do serviço, em JSON, e não mais em texto puro no stderr.
- O relay da outbox abre uma conexão nova depois de um lote que falhou. Antes, uma queda do banco deixava o relay preso num PDO morto, e os eventos paravam de sair até alguém reiniciar o worker.
- Consumidores Kafka: conexão perdida com o banco espera o banco voltar em vez de mandar a mensagem para a DLQ, e um `SIGTERM` no meio das tentativas não confirma o offset. Os dois experimentos estão no laboratório de banco fora do ar.

## [0.3.0] - 2026-09-27

### Added

- Imagem base do PHP (8.3 e 8.4) com as extensões dos serviços e nginx na frente dos apps PHP-FPM.
- Pacote `tucano/feature-flags`: porta `FeatureFlags` com OpenFeature e flagd, cache de avaliação (APCu ou memória) e guard que desliga flags de caos e laboratório em produção.
- `DomainError` com `ErrorCategory` no shared kernel, para o domínio dizer o tipo do problema sem conhecer HTTP.
- Pacote `tucano/messaging`: producer idempotente, consumer at-least-once com retry e DLQ, relay da outbox com `SKIP LOCKED` e inbox, testado contra PostgreSQL e Kafka reais.
- Read models no MongoDB: pacote `tucano/read-models` (migrations com `$jsonSchema` e upsert por versão), coleções `order_views` e `shipment_timelines` criadas no job de migração.
- Serviço `commerce` (Laravel 13, PHP 8.4): esqueleto de API com health checks, erros RFC 9457, correlation id, flags e Snowflake, exposto pelo Kong em `/api/commerce`.
- Serviço `logistics` (Laravel 13, PHP 8.4) com a mesma estrutura do commerce, exposto pelo Kong em `/api/logistics`.
- Schemas PostgreSQL do commerce e do logistics com DDL explícito (constraints, índices parciais, comentários), seeders idempotentes, jobs de migração no compose e testes das constraints contra o banco real.
- Serviço `catalog` (Lumen 11, PHP 8.3) no papel de serviço legado: health checks, erros RFC 9457, correlation id e flags, exposto pelo Kong em `/api/catalog`.
- Schema MySQL do catálogo com DDL explícito (UUIDv7 em `BINARY(16)`, `ENUM`, `CHECK` com `REGEXP_LIKE`), seeders idempotentes com os produtos do estoque, job `catalog-migrate` e testes das constraints.
- Serviço `tracking` (Swoole 6.2, PHP 8.4): servidor HTTP e WebSocket de longa duração com health checks, erros RFC 9457, correlation id no contexto da corrotina e graceful shutdown, exposto pelo Kong em `/api/tracking`.
- Serviços `bff` e `partners-sim` (Node 24, Fastify 5, TypeScript sem build): health checks, erros RFC 9457, correlation id e logs JSON. O bff fica no Kong em `/bff`; o simulador fica só na rede `edge`, e os serviços chegam nele pelo Toxiproxy.
- `make check s=<serviço>` para qualquer serviço: PHP contra a stack, Node no `node:24-alpine`.
- Modelo de dados com o uso do Redis e as tabelas do DynamoDB.

### Changed

- A tag de release sai direto da `origin/main`, sem trocar o working tree: a troca apagava e recriava arquivos montados pelos containers da stack.

### Fixed

- Dependência muda não prende mais o PHP-FPM: timeouts de conexão e leitura em PostgreSQL, MySQL, Redis e MongoDB, `request_terminate_timeout` no FPM e health check sem o retry da query. Com um banco em blackhole, o readiness responde 503 em 2 a 4 s, e não mais 504 depois de 30 s.
- O 405 dos serviços PHP volta com o header `Allow`: o problem details descartava os headers da exceção HTTP.

## [0.2.0] - 2026-09-27

### Added

- Stack local em Docker Compose: PostgreSQL 18, MySQL 8.4, MongoDB 8, Redis 8, Kafka 3.9 com ZooKeeper, Floci, Mailpit, Toxiproxy, flagd e Kong 3.9.
- Feature flags privadas por ambiente (local, staging e production) com OpenFeature e flagd.
- Comandos `make` para operar a stack e job de CI que valida compose, configuração do Kong, flags e scripts.
- Shared kernel PHP (`tucano/shared-kernel`): UUIDv7, Snowflake com sequência em APCu, Base32 de Crockford, `Money`, `Clock`, envelope CloudEvents e o atributo `#[UseCase]`, testado em PHP 8.3 e 8.4.

### Changed

- Documentação revisada: tom direto, primeira pessoa, pontuação simples, tipos de dados precisos e direções corrigidas no context map.

## [0.1.0] - 2026-09-27

### Added

- Estrutura inicial do repositório, Git Flow e convenções de engenharia.
- Blueprint de arquitetura: C4, context map, linguagem ubíqua, eventos, identificadores, máquinas de estados, casos de uso e ADRs 0001 a 0014.
- Fluxo de release: tags SemVer imutáveis e GitHub Release gerada a partir deste changelog.

[Unreleased]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.8.0...develop
[0.8.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/flaviotinococoutinho/chaos_playground/releases/tag/v0.1.0
