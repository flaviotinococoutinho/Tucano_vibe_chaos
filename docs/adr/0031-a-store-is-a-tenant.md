# 0031. Uma loja é um tenant da plataforma

- Status: aceito
- Data: 2026-09-29

## Contexto

A Tucano vendia como uma loja só. Quero mostrar multi-tenancy de verdade: a Tucano vira uma plataforma que hospeda lojas com marca própria, e a entrega (a Tucano Express), os centros de distribuição e o pagamento continuam sendo da plataforma. O [ADR 0030](0030-each-customer-sees-only-its-orders.md) isolou um cliente do outro; este isola uma loja da outra.

Hoje não existe o conceito de loja em lugar nenhum. O SKU é único na plataforma e chaveia o estoque, o cache do catálogo e as cópias do catálogo no commerce e na logistics. Os 18 schemas de evento são fechados (`additionalProperties: false`). O Kong só tem o plugin de correlation id. E a marca está escrita à mão no cabeçalho da web e na tela inicial do BFF.

## Decisão

- **Modelo de pool.** Serviços, bancos e tópicos continuam compartilhados, e a loja vira uma coluna discriminadora (`store`) em todo dado que pertence a uma loja: produtos, pedidos, remessas, páginas de rastreio e read models.
- **O que é da plataforma continua da plataforma**: os centros de distribuição, as transportadoras, a frota, o Kafka, o Redis, o Kong e o PSP. Perante o PSP, a Tucano é a vendedora registrada, como num marketplace.
- **A loja tem um slug imutável** (`arara`, `sabia`, `bemtevi`), um nome, uma frase e uma paleta do design system. O catálogo é o dono desse cadastro, porque é nele que cada loja monta a sua vitrine.
- **Um SKU pertence a uma loja só** e continua único na plataforma. O estoque, o cache e as cópias do catálogo seguem chaveados pelo SKU, e a loja viaja junto.
- **O endereço diz a loja.** No navegador, `/stores/{loja}/...`; no BFF, `/bff/v1/stores/{loja}/...`. A tela inicial da plataforma lista as lojas. O perfil do cliente é da plataforma, e os pedidos são de cada loja.
- **Os serviços recebem a loja no caminho** (`/v1/stores/{loja}/...`), e cada um cobra o isolamento no próprio dado: um recurso de outra loja responde o mesmo 404 de um recurso que não existe.
- **Os eventos levam a loja** num campo novo e opcional, `store`. É uma mudança aditiva, na mesma versão ([ADR 0010](0010-cloudevents-contracts.md)), e o produtor sempre manda o campo.
- **Um pedido é de uma loja só.** O commerce recusa um item que é de outra loja.
- **O Kong dá a cada loja um serviço e um limite de requests próprios**, todos apontando para o mesmo BFF. Uma loja em promoção esgota o próprio limite, não a capacidade das vizinhas.
- **A marca chega pela hipermídia.** A navegação de cada tela diz em que loja a pessoa está e qual paleta ela usa. A web aplica uma das paletas do design system, que garantem o contraste nos temas claro e escuro, em vez de cores livres vindas do banco.
- **O que existia antes das lojas fica sem loja.** Pedidos e remessas antigos continuam nos bancos, e nenhuma loja os mostra.

## Consequências

- Abrir uma loja nova é um cadastro no catálogo e um serviço no Kong.
- Esquecer o filtro da loja numa consulta vaza dados entre lojas. Os testes de isolamento de cada serviço existem para pegar isso; o row-level security do PostgreSQL fica registrado como a próxima camada de defesa.
- Os workers da plataforma (expirar pedidos, conciliar pagamentos, vigiar jornadas) continuam varrendo todas as lojas, porque são operações da plataforma.
- O circuit breaker do PSP é um só: quando o PSP falha, falha para todas as lojas. É o preço de a plataforma ser a vendedora registrada.
- Os números de pedido e os códigos de rastreio continuam globais e ordenados no tempo. Eles não colidem entre lojas, mas deixam estimar quanto a plataforma vende.
- Os probes do caos entram por uma loja, como a web entra.

## Alternativas consideradas

- **Silo, uma stack por loja**: o isolamento mais forte, com o custo multiplicado pelo número de lojas. Numa máquina de 8 GB, não cabe nem a segunda.
- **Bridge, um schema ou um banco por loja**: isola no banco, mas multiplica as migrações e espalha as consultas da plataforma.
- **A loja no subdomínio** (`arara.tucano...`): é o mais comum em plataformas de loja, e o Kong resolveria a loja pelo host. Mas os probes e os testes rodam dentro de containers, onde `*.localhost` não resolve sozinho, e o caminho mantém a regra de que a web só segue os links que recebe.
- **A loja num header entre os serviços**: muda menos rotas, mas um filtro opcional é fácil de esquecer. No caminho, a loja faz parte do endereço do recurso, e uma rota sem ela nem existe.
- **SKU por loja**: deixaria duas lojas usarem o mesmo SKU, mas mudaria a chave do estoque, do cache e das cópias do catálogo em três serviços.
