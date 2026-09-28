# 0. Como eu penso esse tipo de sistema

<img src="../assets/tucano.png" alt="O tucano da Tucano, de perfil, em três tintas sobre papel creme" width="180" align="right">

Antes de visitar a loja, um passeio pelo jeito de pensar que decidiu cada canto dela. Os outros capítulos contam o que a Tucano faz e como; este conta por que ela ficou assim, parada por parada, com a pergunta que eu faço, a resposta que eu dou hoje e o lugar do código onde dá para ver essa resposta funcionando.

Um pouco de contexto primeiro. Passei um tempo usando mais stacks como Java, Kotlin e os frameworks em volta deles. A Tucano é o meu reencontro com uma tecnologia com que eu tinha muita familiaridade, o PHP, e com o Node, para ver o quanto eles evoluíram, principalmente quando se juntam às soluções transversais que hoje atravessam qualquer linguagem: Kafka, Kong, Toxiproxy, OpenFeature, CloudEvents. As linguagens trocam de roupa; as perguntas continuam as mesmas. É dessas perguntas que este capítulo trata.

Quase nada aqui é invenção minha. É leitura aplicada, e cada parada diz de que livro ela veio. No fim tem uma estante com todos eles.

## A informação vem antes da ferramenta

A primeira pergunta nunca é "qual banco". É "de que natureza é esta informação".

Greg Young, que deu nome ao CQRS, gosta de lembrar que quase toda informação de um negócio é um fato: algo que aconteceu, no passado, e que não muda mais. Um pagamento aprovado não deixa de ter sido aprovado; se o dinheiro volta, isso é outro fato, o estorno. O que a gente chama de estado atual, o status do pedido ou o saldo de uma prateleira, é uma conta feita sobre esses fatos.

Separar o fato da conta resolve muita decisão sozinho:

- **Fato se acumula, não se edita.** Os eventos do Kafka são escritos no passado (`order.paid`, `shipment.delivered`), e o histórico de status do pedido (`order_status_transitions`) só ganha linha nova.
- **Conta se refaz.** A lista de pedidos no MongoDB e a página de rastreio no DynamoDB são projeções; cada uma deduplica pelo id do evento e sabe se reconstruir lendo os fatos de novo.
- **Quem decide lê o dono do fato**, e não a conta que outra parte do sistema fez.

Martin Kleppmann, em *Designing Data-Intensive Applications*, me deu o vocabulário para o resto: existe o sistema de registro, onde o fato nasce, e existem os dados derivados dele. E ele separa duas promessas que costumam vir misturadas, pontualidade e integridade. Uma cópia atrasada fere a pontualidade, e isso se resolve sozinho em segundos. Uma regra violada fere a integridade, e isso não se resolve sozinho nunca. Daí vem a frase que atravessa o projeto inteiro: **a cópia pode atrasar, mas nunca decide.** O capítulo [a natureza da informação](03-informacao.md) mostra quem é dono de cada fato.

## Até onde normalizar

Normalizar, desde que Edgar Codd propôs o modelo relacional em 1970, é guardar cada fato num lugar só. Eu levo isso a sério do lado que escreve, porque é lá que um fato duplicado vira dois fatos que discordam. Mas eu paro em três lugares, cada um com um motivo:

1. **Um valor congelado não é uma referência.** O pedido guarda o preço de cada item (`order_lines.unit_price_cents`) e o endereço de entrega inteiro, em vez de apontar para o catálogo ou para um cadastro. Isso não é desnormalizar: o preço de ontem, no pedido de ontem, é um fato daquele momento, e ele não pode mudar quando o catálogo muda o preço hoje.
2. **Um valor que nasce e morre inteiro não precisa de tabela.** As divisões do endereço (estado, município, bairro) ficam numa coluna `jsonb` do pedido, com um `CHECK` da forma. Ninguém muda o bairro de um endereço congelado, então uma tabela de divisões normalizaria sem proteger regra nenhuma ([ADR 0020](../adr/0020-address-by-thoroughfare-and-divisions.md)).
3. **Do lado que lê, eu desnormalizo de propósito.** A linha do tempo de uma remessa é um documento pronto para a tela, com tudo junto, porque ali o custo que importa é o da leitura, e o risco de discordância é pequeno: a projeção é derivada e pode ser refeita.

O critério cabe numa frase: normalizo até cada regra morar num lugar só, e paro quando a próxima tabela serviria à estética e não a uma regra.

## Regra no banco, até onde

É a pergunta que mais divide opinião, e a minha resposta é: **o banco guarda os invariantes, e o domínio guarda as decisões.**

Um invariante é o que precisa ser verdade para qualquer um que escreva naquela tabela: o serviço de hoje, o worker de amanhã, uma migration, e a pessoa que abriu o `psql` às três da manhã. Esses moram no banco, como `CHECK`, `UNIQUE`, chave estrangeira e `NOT NULL`:

- não reservar mais do que existe: `CHECK (reserved <= on_hand)`;
- no máximo um pagamento com sucesso por pedido: um índice único parcial;
- só pedido que saiu tem código de rastreio, e todo pedido cancelado diz por quê: `CHECK` nos dois sentidos;
- a mesma chave de idempotência, uma vez por escopo: a chave primária.

Uma decisão depende de contexto, de tempo ou de outro sistema: se o pedido ainda pode ser pago, que transportadora leva, quando desistir do PSP. Essas moram no domínio, em código testado sem banco. Por isso não existe trigger nem procedure com regra de negócio no projeto: eles escondem decisão num lugar que o teste unitário não vê e que o code review raramente lê.

Um `CHECK` é documentação que o banco executa, e ele tem uma vantagem sobre qualquer teste: vale também para o bug que ainda não foi escrito. A tabela das [regras que moram no banco](../architecture/data-model.md#regras-que-moram-no-banco) tem cada uma, com o teste de integração que prova que o banco recusa a linha.

## Pureza nos atributos

Um atributo puro diz uma coisa só, e dá para entender sem ler o código em volta. Na prática, isso vira quatro hábitos.

- **Estado é um nome, não uma combinação.** `is_paid`, `is_shipped` e `is_cancelled` juntos permitem oito combinações, e o negócio conhece só algumas. O pedido tem um `status`, um enum que sabe para onde pode ir ([ADR 0011](../adr/0011-state-machines-without-flags.md)).
- **Nulo quer dizer "ainda não", e o banco sabe quando.** O código de rastreio é nulo até a coleta, e um `CHECK` garante que ele só existe depois dela. Um nulo sem regra é uma pergunta sem resposta.
- **A unidade faz parte do nome e do tipo.** Dinheiro é inteiro em centavos com a moeda ao lado, nunca `float`, como no padrão Money do *Patterns of Enterprise Application Architecture*, de Martin Fowler. Duração diz a unidade no nome (`UPSTREAM_TIMEOUT_MS`), e o CI recusa a variável que não diz. UF é `CHAR(2)` e e-mail é `VARCHAR(254)`: o tipo conta a verdade sobre o tamanho.
- **O valor é conferido uma vez, na borda.** Alexis King resumiu em três palavras, "parse, don't validate": em vez de validar uma string e seguir carregando a string, eu a transformo num tipo que só existe se for válido (`Money::of`, `TrackingCode::of`, `PersonName::of`). Daí para dentro, ninguém precisa desconfiar. E um nome de pessoa não é uma string qualquer: ele mora num `Sensitive`, que se imprime mascarado em qualquer log ([ADR 0024](../adr/0024-sensitive-data-behind-a-proxy.md)).

## Abstrato na medida certa

Abstração é uma aposta sobre o que vai mudar. David Parnas perguntou, em 1972, que decisão tem mais chance de mudar, e eu ainda faço essa pergunta antes de criar qualquer interface. Quando a resposta é "o fornecedor" (o banco, a fila, o PSP, a fonte de flags), a decisão ganha uma parede: um port com nome de intenção (`ForStoringOrders`, `ForChargingCards`, até `ForRunningTransactions`) e um adapter do outro lado. Quando a resposta é "nada, por enquanto", eu não abstraio.

Martin Fowler chama de Yagni a disciplina de não construir o que você acha que vai precisar, e lembra que o custo não é só o do código a mais: é o de carregar e entender aquele código até o dia em que ele for útil, se esse dia chegar. A medida vale ao contrário também. Duas cópias iguais, conferidas por um `diff` no CI, me custam menos do que uma biblioteca compartilhada entre o BFF e o partners-sim ([ADR 0016](../adr/0016-copied-node-platform.md)). *O Programador Pragmático* ajuda a ver por quê: DRY fala de conhecimento, não de texto, e um conhecimento com uma fonte só continua sendo um só, mesmo escrito em dois arquivos.

No domínio, a medida vem do DDD. Eric Evans ensinou a separar contextos: o produto do catálogo não é o produto do pedido, e forçar um modelo único para os dois é a abstração errada mais cara que existe. Vaughn Vernon, em *Implementing Domain-Driven Design*, deu regras práticas para os agregados, e eu sigo as quatro: proteger dentro da fronteira só os invariantes de verdade, manter o agregado pequeno, apontar para outro agregado pela identidade e aceitar consistência eventual fora da fronteira. O pedido aponta para o produto pela SKU, nunca pelo objeto, e a remessa mora em outro serviço, acompanhando o pedido por eventos.

E há uma exceção que eu aceito de olhos abertos: o pedido e a reserva de estoque mudam na mesma transação, contra a regra de mudar um agregado por vez. Vender sem reservar é o erro que este negócio não perdoa, os dois moram no mesmo serviço e no mesmo banco, e a chave estrangeira da reserva só é conferida no `COMMIT`. Regra boa é a que a gente sabe quando quebrar, e deixa escrito por quê.

## Padrões a serviço da escala

Escala, para mim, começa pela pergunta de onde está o gargalo, e para quem. O cubo de escala de Martin Abbott e Michael Fisher, de *The Art of Scalability*, é o mapa que eu uso, porque separa três jeitos de crescer que costumam ser confundidos:

| Eixo | O que é | Onde já está pronto na Tucano |
|---|---|---|
| X: clonar | mais réplicas iguais, sem estado | a API PHP não guarda nada entre requests; o estado do circuit breaker mora no Redis, então toda réplica enxerga o mesmo circuito; os workers dividem o trabalho com `FOR UPDATE SKIP LOCKED`, e os consumidores do Kafka com consumer groups |
| Y: separar por função | cada parte do negócio no seu serviço | um serviço por subdomínio, e a leitura separada da escrita |
| Z: particionar por chave | cada réplica cuida de uma fatia dos dados | os eventos usam o id do agregado como chave no Kafka, então a ordem vale por pedido e por remessa; o rastreio lê o DynamoDB por chave |

Greg Young deu nome ao CQRS, e Martin Fowler escreveu o aviso que eu levo junto: é um padrão para lugares específicos, não para o sistema inteiro. Aqui ele aparece onde a leitura tem outra forma e outro volume, na lista de pedidos, na linha do tempo e no rastreio público. A tela de um pedido específico, logo depois do pagamento, continua lendo o PostgreSQL, porque ali a pessoa quer o fato, e não a cópia.

E tem o que Eric Brewer apresentou em 2000, e que Seth Gilbert e Nancy Lynch provaram em 2002: quando a rede se parte, cada operação escolhe entre responder com o que tem ou recusar para não errar. O próprio Brewer escreveu, doze anos depois, que essa escolha se faz operação por operação, e não uma vez para o sistema inteiro. A Tucano faz as duas escolhas, e dois experimentos provam:

- **reservar estoque escolhe consistência.** Sem o PostgreSQL do commerce, o pedido não fecha, e a loja responde `503` em 0,13 s, dizendo quando tentar de novo;
- **rastrear uma entrega escolhe disponibilidade.** Sem o PostgreSQL da logística, o rastreio continua respondendo em 0,03 s, porque lê a cópia no DynamoDB, que pode estar alguns segundos atrás;
- **o BFF escolhe tela por tela.** Com o commerce cortado, só as telas do commerce respondem `503`, e o catálogo continua abrindo.

Nenhum desses padrões entrou para escalar o que ainda não precisa. Cada um entrou porque um laboratório mostrou o problema, e o [playground do caos](04-caos.md) conta qual.

## Crescer sem entropia, agora que código é barato

*O Programador Pragmático* tem a imagem que eu mais repito: a janela quebrada. Um prédio com uma janela quebrada que ninguém conserta logo tem outras, porque o descuido vira norma. Software apodrece do mesmo jeito, e o livro chama isso de entropia.

Hoje escrever código ficou barato e rápido. Uma tela, um endpoint, um serviço inteiro saem em minutos, e isso é uma oportunidade enorme. Ela muda o lugar do gargalo: o caro deixou de ser digitar e passou a ser decidir, revisar e manter o conjunto coerente. Frederick Brooks separou, em 1986, a complexidade essencial (o problema é difícil) da acidental (a ferramenta atrapalha). Quando produzir código fica fácil, produzir complexidade acidental fica fácil também. O que segura um sistema nessa hora é o que Brooks chamou, em *O Mítico Homem-Mês*, de integridade conceitual: o mesmo problema com a mesma solução em todo lugar.

Por isso, neste projeto, toda regra que importa é algo que um computador confere, e não algo que alguém precisa lembrar:

- **A regra vira teste.** A direção das dependências, as fronteiras entre pacotes, os lugares onde um dado pessoal pode aparecer, a configuração: cada uma é uma fitness function que quebra o build ([abstrações](05-abstracoes.md)).
- **A decisão vira texto com data.** Toda escolha cara de desfazer tem um ADR com o contexto, as alternativas e o custo. Quem chega depois discorda com argumento, não com palpite.
- **O contrato vira exemplo testado dos dois lados.** Uma tela do BFF é um JSON de exemplo que o BFF precisa gerar byte a byte e que a web precisa desenhar. Um evento é um JSON Schema que cada publicação nos testes precisa cumprir.
- **A hipótese vira arquivo.** "A loja aguenta o PSP lento" deixou de ser uma frase num markdown e virou um experimento que qualquer pessoa roda com um comando.
- **A mudança vem em passos pequenos e contados.** Martin Fowler, em *Refatoração*, ensina a mudar a estrutura em passos que mantêm o sistema funcionando. Aqui cada PR diz por que existe, o que foi pesado e como ver funcionando, e o CHANGELOG conta cada versão.
- **O caminho para o novo já está desenhado.** Um caso de uso novo é uma ficha, um port com nome de intenção, um `#[UseCase]` e um teste. Uma ilustração nova é um original e um `make web-art`. Quando o caminho certo é o mais fácil, a entropia perde a vantagem.

A segunda edição do *Programador Pragmático* resumiu tudo isso num valor só, que eu uso como bússola: bom design é o que deixa o sistema mais fácil de mudar. Se uma mudança deixou a próxima mais difícil, ela ainda não terminou.

Uma última coisa, que o caos me ensinou enquanto eu escrevia este guia: **afirmação sobre resiliência só vale depois de medida.** Eu ia escrever que a loja recusa um pedido com honestidade quando o banco cai. Medi antes, e ela respondia um `500` que não dizia nada. A frase virou um experimento, o experimento virou uma correção, e a correção virou o [ADR 0026](../adr/0026-a-database-outage-is-unavailability.md).

## Soluções para o momento

Cada decisão deste projeto é para este momento: um laboratório numa máquina só, uma pessoa escrevendo, e o objetivo de aprender. Algumas mudariam num sistema com times e tráfego de verdade, e eu gosto de deixar isso escrito, porque saber quando uma decisão deixa de valer faz parte da decisão.

| Hoje | Quando mudaria | Para onde iria |
|---|---|---|
| o relay lê a outbox de tempos em tempos | quando a latência do relay ou a carga no banco pesarem | captura de mudanças (CDC) lendo o log do PostgreSQL, sem mexer em nenhum produtor |
| o `X-Correlation-Id` carimbado pelo Kong | quando eu precisar do tempo de cada trecho, e não só do fio da meada | OpenTelemetry, com traces que atravessam o Kafka |
| cliente convidado num cookie | quando existir conta de verdade | login e sessão, e o carrinho da pessoa entre aparelhos |
| um PostgreSQL com um banco por serviço | quando um serviço crescer mais que os outros | cada banco no seu servidor, réplicas de leitura e, no limite, pedidos particionados por chave |
| o catálogo em Lumen, no papel de legado | quando o legado virar gargalo | estrangular aos poucos, como no *Strangler Fig* de Fowler: rotas novas num serviço novo, atrás do mesmo Kong |

*O Programador Pragmático* diz que não existem decisões finais, e os adapters são o meu jeito de levar isso a sério: trocar o Floci pela AWS de verdade, ou o flagd por outra fonte de flags, muda um adapter e mais nada. Decisão boa hoje é a que deixa barata a decisão de amanhã.

## Uma estante para o passeio

| Livro ou ideia | O que eu levei dele | Onde aparece |
|---|---|---|
| *O Programador Pragmático*, Andrew Hunt e David Thomas (1999; edição de 20 anos em 2019) | entropia e janela quebrada, DRY como conhecimento, ortogonalidade, reversibilidade, e o valor de deixar fácil de mudar | as fitness functions, a plataforma Node copiada com `diff`, os adapters |
| *O Mítico Homem-Mês*, Frederick Brooks (1975), e o ensaio *Não existe bala de prata* (1986) | integridade conceitual, e a diferença entre complexidade essencial e acidental | as convenções, os ADRs, o custo escrito de cada escolha |
| Martin Fowler: *Refatoração* (1999), *Patterns of Enterprise Application Architecture* (2002) e os artigos sobre CQRS, Yagni e Strangler Fig | passos pequenos com teste, o padrão Money, CQRS só onde paga, não construir antes da hora, trocar o legado aos poucos | `Money`, as projeções de leitura, o catálogo no papel de legado |
| *Designing Data-Intensive Applications*, Martin Kleppmann (2017) | sistema de registro e dados derivados, o log como espinha, e a diferença entre pontualidade e integridade | ACID onde decide e BASE onde mostra, as projeções que se reconstroem |
| *Domain-Driven Design*, Eric Evans (2003) | linguagem ubíqua, contextos delimitados e o mapa entre eles | os serviços por subdomínio, o BFF como camada anticorrupção |
| *Implementing Domain-Driven Design* (2013) e *Domain-Driven Design Distilled* (2016), Vaughn Vernon | as regras dos agregados: invariante de verdade, agregado pequeno, referência pela identidade, consistência eventual fora da fronteira | o pedido que aponta para o produto pela SKU, a remessa que acompanha o pedido por eventos |
| Greg Young, CQRS e event sourcing (2010) | a informação como fato no passado, o estado como conta sobre os fatos, e modelos separados para decidir e para ler | os eventos no passado, o histórico de status, as projeções |
| Eric Brewer, o teorema CAP (2000), provado por Seth Gilbert e Nancy Lynch (2002) e revisto por ele em 2012 | na partição, cada operação escolhe entre responder e acertar | a reserva que prefere recusar, o rastreio que prefere responder, o `503` tela por tela |

O capítulo [conceitos, ganhos e custos](06-conceitos.md) abre cada uma dessas ideias com o que ela dá, o que ela cobra e para onde pode ir.

Agora, o passeio de verdade: [a Tucano e a jornada de um pedido](01-a-tucano.md).
