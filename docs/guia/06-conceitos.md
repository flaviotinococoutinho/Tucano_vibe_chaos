# 6. Conceitos, ganhos e custos

Nenhum conceito aqui é bala de prata. Cada um resolve um problema específico e cobra alguma coisa por isso, e a graça de um laboratório é poder sentir as duas coisas. Muitos são antigos, de décadas antes de Kafka ou Kubernetes, e continuam certos porque tratam de gente e de mudança, que não saíram de moda.

Cada entrada tem o mesmo formato: o que é, o **ganho**, o **custo**, uma **possibilidade** de evolução e **onde** ele aparece no projeto.

## Integridade conceitual

Frederick Brooks, *The Mythical Man-Month* (1975): um sistema que reflete um conjunto coerente de ideias é melhor do que um que junta boas ideias soltas.

- **Ganho**: quem aprende uma parte do sistema já sabe como são as outras. Um erro sempre é RFC 9457, uma configuração sempre vem do ambiente, um contexto sempre chama o outro por um port.
- **Custo**: às vezes a solução local mais esperta é recusada porque destoa. Uniformidade tem preço em criatividade pontual.
- **Possibilidade**: um ADR para cada exceção deliberada, como o catálogo que publica sem outbox, mantém a coerência sem esconder o desvio.
- **Onde**: as [convenções](../engineering/conventions.md) e os [ADRs](../adr/README.md).

## Não existe bala de prata

Brooks, de novo, em 1986: nenhuma técnica sozinha vai multiplicar a produtividade, porque boa parte da dificuldade é essencial (o problema é difícil) e não acidental (a ferramenta atrapalha).

- **Ganho**: tira a ansiedade de achar a ferramenta certa e põe o esforço onde a dificuldade mora: estoque disputado, pagamento sem resposta, encomenda perdida.
- **Custo**: exige escrever o custo de cada escolha, o que dá trabalho e às vezes desanima.
- **Possibilidade**: medir o custo, e não só descrever, como os laboratórios fazem com o overselling e o circuit breaker.
- **Onde**: a seção de consequências de cada ADR.

## Entropia e janelas quebradas

Andrew Hunt e David Thomas, *O Programador Pragmático* (1999, com a edição de 20 anos em 2019): software apodrece quando o descuido vira norma, e o valor que resume o bom design é deixar o sistema fácil de mudar.

- **Ganho**: a janela quebrada é consertada no dia, porque a regra que ela quebra é um teste; ninguém precisa decidir se vale a pena.
- **Custo**: cada regra conferida é código a manter, e uma regra que ninguém entende vira burocracia.
- **Possibilidade**: medir o que apodrece devagar, como o tempo de build e o tamanho do bundle, com o mesmo rigor das fronteiras.
- **Onde**: as fitness functions, e a reversibilidade dos adapters: trocar o Floci pela AWS muda um adapter e mais nada.

## Refatoração e Yagni

Martin Fowler, *Refatoração* (1999, segunda edição em 2018) e os artigos do seu site: mudar a estrutura em passos pequenos que mantêm o sistema funcionando, e não construir o que você só acha que vai precisar.

- **Ganho**: cada PR muda uma coisa, com teste, e dá para desfazer; a abstração nasce quando o segundo uso aparece, não antes.
- **Custo**: paciência. O caminho em passos pequenos é mais longo no papel do que o grande salto, e às vezes a duplicação fica visível por um tempo.
- **Possibilidade**: o *Strangler Fig*, do mesmo autor, para aposentar o catálogo legado: rotas novas num serviço novo, atrás do mesmo Kong, até o Lumen não atender mais ninguém.
- **Onde**: o histórico de PRs, a plataforma Node copiada em vez de compartilhada ([ADR 0016](../adr/0016-copied-node-platform.md)) e o padrão Money, do *Patterns of Enterprise Application Architecture* (2002).

## Ocultação de informação

David Parnas, *On the Criteria to Be Used in Decomposing Systems into Modules* (1972): cada módulo esconde uma decisão que pode mudar, e os outros dependem só da interface dele.

- **Ganho**: a mudança fica local. Trocar como o estoque é reservado mexe num adapter; o caso de uso nem percebe.
- **Custo**: mais arquivos e mais indireção; ler o caminho inteiro de um request pede saltar de interface em interface.
- **Possibilidade**: medir o acoplamento real (quais arquivos mudam juntos no histórico do Git) para descobrir paredes no lugar errado.
- **Onde**: ports, fachadas entre pacotes, barrels do TypeScript, o `Sensitive`.

## Separação de interesses

Edsger Dijkstra (1974): estudar um aspecto de cada vez, sabendo que os outros existem, mas sem misturar.

- **Ganho**: o domínio é testado sem banco, sem HTTP e sem Kafka; os testes unitários rodam em segundos.
- **Custo**: tradução nas bordas. Todo dado que entra por HTTP vira comando, todo resultado vira view.
- **Possibilidade**: testes de contrato entre adapter e porta dos dois lados, para a tradução não esconder bug.
- **Onde**: as camadas `Domain`, `Application` e `Adapter` de cada pacote.

## Lei de Conway

Melvin Conway (1968): o desenho de um sistema acaba copiando a estrutura de comunicação de quem o constrói.

- **Ganho**: serviços por subdomínio ([ADR 0002](../adr/0002-services-per-subdomain.md)) cabem na cabeça de um time cada; um time de logística mudaria a logística sem pedir licença ao commerce.
- **Custo**: com um desenvolvedor só, as fronteiras existem por disciplina, não por necessidade. É o preço de treinar para um mundo com vários times.
- **Possibilidade**: usar a lei ao contrário, desenhando os times a partir das fronteiras que o domínio pede.
- **Onde**: o [context map](../architecture/context-map.md).

## Domain-Driven Design

Eric Evans (2003): o software fala a língua do negócio, cada contexto delimitado tem o seu modelo, e o mapa de contextos diz como eles se relacionam.

- **Ganho**: o código conversa com quem conhece o negócio. `markAsShipped`, `ReservationExpired`, `payment_declined` são palavras do domínio, não do banco.
- **Custo**: o mesmo conceito existe em vários modelos (o produto do catálogo não é o produto do pedido), e manter as traduções dá trabalho.
- **Possibilidade**: event storming com gente do negócio de verdade para validar a linguagem.
- **Onde**: a [linguagem ubíqua](../architecture/ubiquitous-language.md), os contratos em `contracts/events` como linguagem publicada, e o BFF como camada anticorrupção.

## Agregados pequenos

Vaughn Vernon, *Implementing Domain-Driven Design* (2013) e *Domain-Driven Design Distilled* (2016): proteger dentro de um agregado só os invariantes de verdade, manter o agregado pequeno, apontar para outro agregado pela identidade e aceitar consistência eventual fora da fronteira.

- **Ganho**: transações curtas e pouca disputa de linha; o pedido não carrega o produto inteiro, só a SKU e o preço daquele momento.
- **Custo**: o que atravessa agregados passa a ser eventual, e a interface precisa mostrar o "ainda não" com honestidade.
- **Possibilidade**: tirar a reserva de estoque da transação do pedido, com uma saga e uma compensação, se um dia os dois morarem em serviços diferentes.
- **Onde**: o pedido e a remessa, que se acompanham por eventos. A exceção consciente é a reserva, que muda na mesma transação do pedido porque vender sem reservar é o erro que este negócio não perdoa.

## Portas e adaptadores

Alistair Cockburn, arquitetura hexagonal (2005): a aplicação conversa com o mundo por portas, e cada tecnologia se pluga por um adaptador.

- **Ganho**: fornecedor mora na borda. Floci vira AWS de verdade, flagd vira outra fonte de flags, e o domínio não muda ([ADR 0022](../adr/0022-vendors-live-in-adapters.md)).
- **Custo**: uma interface para cada dependência, mesmo quando só existe uma implementação.
- **Possibilidade**: adapters de teste compartilhados como um kit, para cada caso de uso ter dublês prontos.
- **Onde**: os ports `For...` em `Application/Port`, e o Deptrac.

## Casos de uso com garantias

Cockburn, *Writing Effective Use Cases* (2000): o sistema é descrito pelo que cada ator quer alcançar, com garantias mínimas (o que vale mesmo na falha) e de sucesso.

- **Ganho**: as garantias mínimas viram testes e transações. "Um pedido devolvido nunca fica com o pagamento" está escrito, testado e na mesma transação.
- **Custo**: documento que precisa acompanhar o código. Aqui um teste de arquitetura liga os dois.
- **Possibilidade**: gerar o esqueleto do teste de aceitação a partir do cenário principal da ficha.
- **Onde**: [casos de uso](../use-cases/README.md) e o atributo `#[UseCase]`.

## Estados impossíveis não existem

Yaron Minsky (2011) resumiu como "tornar estados ilegais irrepresentáveis": o tipo não deixa existir a combinação que o negócio proíbe.

- **Ganho**: um pedido nunca é pago e cancelado ao mesmo tempo, porque o status é um enum com transições, não um conjunto de booleanos.
- **Custo**: cada transição nova precisa ser pensada e escrita; não dá para "só ligar uma flag".
- **Possibilidade**: gerar o diagrama das máquinas de estados a partir do enum, para a documentação nunca desatualizar.
- **Onde**: [máquinas de estados](../architecture/state-machines.md) e o [ADR 0011](../adr/0011-state-machines-without-flags.md).

## Parse, don't validate

Alexis King (2019): em vez de conferir um valor e seguir carregando o tipo cru, transformar o valor, na borda, num tipo que só existe se for válido.

- **Ganho**: a desconfiança fica num lugar só. Um `TrackingCode` que existe é um código válido, e nenhuma função precisa conferir de novo.
- **Custo**: um tipo para cada conceito, e a tradução nas bordas, da string para o tipo e de volta.
- **Possibilidade**: gerar os tipos da borda a partir dos JSON Schemas dos contratos, para o parser e o contrato nunca discordarem.
- **Onde**: os construtores nomeados dos value objects (`Money::of`, `TrackingCode::of`, `PersonName::of`) e o `Sensitive`.

## Outbox e inbox

O padrão da outbox transacional, que Chris Richardson popularizou entre os padrões de microsserviços, e o consumidor idempotente do outro lado.

- **Ganho**: o fato e o aviso do fato nunca se separam. Se o Kafka cair, o evento espera na tabela; se chegar duas vezes, a inbox descarta.
- **Custo**: latência de um relay, uma tabela a mais em cada banco, e a disciplina de nunca publicar direto.
- **Possibilidade**: trocar o relay por captura de mudanças (CDC) lendo o log do PostgreSQL, sem mudar os produtores.
- **Onde**: `packages/php/messaging` e o [ADR 0008](../adr/0008-transactional-outbox.md).

## Idempotência

Pat Helland, *Life beyond Distributed Transactions* (2007) e *Idempotence Is Not a Medical Condition* (2012): num sistema distribuído a mensagem chega de novo, e o receptor precisa aguentar.

- **Ganho**: retry vira seguro. O clique duplo, o timeout do cliente e a reentrega do Kafka não criam um segundo pedido nem cobram duas vezes.
- **Custo**: uma chave para guardar, uma janela de validade, e o cuidado de a chave casar sempre com o mesmo corpo.
- **Possibilidade**: expor o `Idempotent-Replayed` na web para mostrar à pessoa que a segunda tentativa não duplicou nada.
- **Onde**: `Idempotency-Key` nos `POST`, a inbox dos consumidores, o cookie do cliente convidado no BFF.

## Padrões de estabilidade

Michael Nygard, *Release It!* (2007): timeout, circuit breaker, falhar rápido, e a certeza de que toda integração vai falhar um dia.

- **Ganho**: uma dependência lenta não derruba quem depende dela. O checkout responde 503 em milissegundos enquanto o PSP se recupera.
- **Custo**: mais estados para entender (circuito aberto, meio aberto) e o risco de recusar trabalho que teria dado certo.
- **Possibilidade**: bulkheads por dependência no BFF, para um serviço lento não ocupar a vez dos outros.
- **Onde**: o [laboratório do circuit breaker](../labs/circuit-breaker.md) e os timeouts do BFF.

## Dados derivados e consistência eventual

Martin Kleppmann, *Designing Data-Intensive Applications* (2017), e a ideia de BASE em contraste com ACID: existe o sistema de registro, e existem visões derivadas dele.

- **Ganho**: cada leitura usa o banco que faz aquilo bem, e escala sem pesar na escrita.
- **Custo**: a visão atrasa, e uma tela que mostra a visão logo depois da escrita pode mostrar o passado.
- **Possibilidade**: reconstruir qualquer projeção do zero a partir do Kafka, como teste de que ela é mesmo derivada.
- **Onde**: [ADR 0012](../adr/0012-acid-writes-base-reads.md) e o capítulo [a natureza da informação](03-informacao.md).

## Fatos e CQRS

Greg Young, que deu nome ao CQRS por volta de 2010: a informação de um negócio é uma sequência de fatos no passado, o estado atual é uma conta sobre eles, e decidir e ler podem usar modelos diferentes.

- **Ganho**: cada leitura tem a forma da tela que a usa, e nenhum fato se perde para dar lugar a outro; um erro se corrige com um fato novo, como um estorno.
- **Custo**: dois modelos, o caminho entre eles, e o atraso da leitura. Fowler avisa que o padrão serve a partes específicas de um sistema, não ao sistema inteiro.
- **Possibilidade**: event sourcing no pagamento, onde a história inteira (cobrança, webhook, conciliação, estorno) já é o que importa.
- **Onde**: os eventos no passado, o histórico de status que só ganha linha nova, e as projeções da lista de pedidos, da linha do tempo e do rastreio.

## CAP e PACELC

Eric Brewer (2000), provado por Seth Gilbert e Nancy Lynch (2002) e revisto pelo próprio Brewer em 2012; Daniel Abadi completou com o PACELC (2012): na partição, escolher entre disponibilidade e consistência; sem partição, entre latência e consistência.

- **Ganho**: a escolha fica explícita e por operação. Fechar um pedido prefere recusar; rastrear prefere responder com a cópia.
- **Custo**: cada operação precisa de uma resposta pensada para a falha, e a interface precisa saber mostrar "tente de novo" e "pode estar alguns segundos atrás".
- **Possibilidade**: mostrar na página de rastreio há quanto tempo a cópia foi atualizada, para a pessoa saber o quanto confiar nela.
- **Onde**: os experimentos [`commerce-database-out`](../../chaos/experiments/commerce-database-out.json) e [`tracking-without-its-database`](../../chaos/experiments/tracking-without-its-database.json), e o capítulo [a natureza da informação](03-informacao.md#quando-a-rede-se-parte).

## O cubo de escala

Martin Abbott e Michael Fisher, *The Art of Scalability* (2009): crescer clonando (eixo X), separando por função (eixo Y) ou particionando por chave (eixo Z).

- **Ganho**: nomeia o jeito de crescer antes de escolher a ferramenta, e mostra o que já está pronto: API sem estado, workers com `SKIP LOCKED`, serviços por subdomínio, eventos com a chave do agregado.
- **Custo**: cada eixo cobra o seu. Clonar exige estado fora do processo, separar exige contratos, particionar exige escolher bem a chave.
- **Possibilidade**: particionar os pedidos por cliente ou por centro de distribuição, se um PostgreSQL só um dia não der conta.
- **Onde**: o circuit breaker no Redis, os consumer groups do Kafka, e as três partições de cada tópico.

## Identidade ordenada no tempo

UUIDv7 (RFC 9562, 2024) e o Snowflake que o Twitter publicou em 2010.

- **Ganho**: id gerado sem ir ao banco, que ainda assim chega em ordem ao índice; e números que uma pessoa consegue ler e ditar.
- **Custo**: o id revela quando o registro nasceu, e o Snowflake precisa de um id de processo único.
- **Possibilidade**: um serviço de ids só, se um dia os processos passarem de centenas.
- **Onde**: [identificadores](../architecture/identifiers.md).

## The Twelve-Factor App

Adam Wiggins e a Heroku (2011): configuração no ambiente, processos descartáveis, logs como fluxo de eventos.

- **Ganho**: a mesma imagem roda em qualquer ambiente, e toda variável tem nome, unidade e documentação conferidos pelo CI.
- **Custo**: muita variável, e a tentação de configurar o que deveria ser código.
- **Possibilidade**: um schema da configuração que valida os ambientes antes do deploy.
- **Onde**: [configuração](../operations/configuration.md) e o [ADR 0021](../adr/0021-configuration-from-the-environment.md).

## Fitness functions

Neal Ford, Rebecca Parsons e Patrick Kua, *Building Evolutionary Architectures* (2017): uma característica de arquitetura que importa vira um teste automático.

- **Ganho**: a regra não depende de quem revisa o PR estar atento naquele dia.
- **Custo**: teste que precisa de manutenção, e o risco de engessar uma regra que devia mudar.
- **Possibilidade**: medir também o que degrada devagar, como o tempo de build e o tamanho do bundle da web.
- **Onde**: a tabela "quem cobra" do capítulo [abstrações](05-abstracoes.md).

## Hipermídia

Roy Fielding, na tese que definiu REST (2000): o servidor manda, junto com os dados, os links e as ações possíveis, e o cliente só segue. O formato aqui é Siren, de Kevin Swiber.

- **Ganho**: o fluxo mora no servidor. Um passo novo no checkout muda o BFF, e a web nem fica sabendo.
- **Custo**: uma web genérica, menos livre para inventar interação, e um vocabulário que precisa de cuidado para não inchar.
- **Possibilidade**: a mesma API servindo um app de celular com um intérprete nativo.
- **Onde**: o [contrato do BFF](../../contracts/http/bff/README.md) e o [ADR 0023](../adr/0023-server-driven-ui-with-siren.md).

## Engenharia do caos

Os *Principles of Chaos Engineering*, que o time da Netflix publicou em 2015: hipótese sobre o estado estável, falhas do mundo real, experimento, e o menor raio de estrago possível.

- **Ganho**: a resiliência deixa de ser crença. Cada laboratório mudou código.
- **Custo**: tempo, e o desconforto de descobrir que o sistema não era tão robusto.
- **Possibilidade**: rodar um experimento curto no CI, como o de overselling já roda.
- **Onde**: o capítulo [playground do caos](04-caos.md).

## Privacidade por padrão

A LGPD (Lei 13.709/2018) pede, entre os princípios, que se use só o dado necessário; o *Privacy by Design* de Ann Cavoukian pede que a proteção seja o padrão, não um opcional.

- **Ganho**: log, erro e dump não vazam nome, e-mail ou token por acidente, e o caminho do valor real fica visível no código.
- **Custo**: um `reveal()` a mais em cada ponto de saída, e a disciplina de não burlar.
- **Possibilidade**: criptografia no armazenamento e *crypto-shredding* para atender um pedido de exclusão sem reescrever eventos.
- **Onde**: o [ADR 0024](../adr/0024-sensitive-data-behind-a-proxy.md).

Próximo capítulo: [padrões e RFCs](07-padroes.md).
