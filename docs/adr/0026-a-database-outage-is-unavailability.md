# 0026. Tratar banco fora do ar como indisponibilidade, não como erro interno

- Status: aceito
- Data: 2026-09-28

## Contexto

Com o PostgreSQL do commerce fora, fechar um pedido respondia `500 Internal Server Error`, com o detalhe escondido. O `ProblemDetails` dos serviços PHP dava resposta própria a três tipos de erro: validação, erro HTTP deliberado e erro de domínio. Todo o resto virava 500, e a queda de um banco caía no resto.

Um 500 diz que o servidor encontrou algo que não soube tratar, e não diz se vale tentar de novo. O BFF lê 502, 503 e 504 como "agora não" e devolve à web um 503 com `Retry-After`; um 500 ele trata como falha sem conserto. Na prática, a queda passageira de um banco chegava à pessoa como um erro sem saída, quando a verdade era "tente daqui a pouco".

O [laboratório do banco fora do ar](../labs/database-outage.md) olhava para os workers e deixava a API de lado, porque ela reconecta sozinha a cada request. Foi o experimento `commerce-database-out` que perguntou o que a API respondia durante a queda.

## Decisão

- Conexão recusada ou perdida com o banco é indisponibilidade: `503 Service Unavailable` com `Retry-After: 5`, o tempo de um restart do banco da stack.
- O reconhecimento usa a lista de mensagens que o próprio Laravel mantém para cada driver (a `LostConnectionDetector` no Laravel 13, o trait `DetectsLostConnections` no Lumen 11), aplicada só a `PDOException` (o `QueryException` é uma) e à `LostConnectionException`.
- O `detail` é um texto fixo, porque a mensagem do driver traz o host e a porta do banco.
- Qualquer outro erro de banco (chave duplicada, `CHECK` violado, sintaxe) continua um 500 com o detalhe escondido: aquilo é bug, e tentar de novo não conserta bug.
- Vale para os três serviços PHP. As cópias do commerce e da logistics continuam idênticas, e o CI compara as duas.

## Consequências

- A web mostra "Fora do ar por um instante" e quando tentar de novo, porque o BFF repassa o `Retry-After` do serviço.
- Tentar de novo é seguro: todo `POST` que cria alguma coisa exige `Idempotency-Key`, então a segunda tentativa não duplica um pedido que tenha chegado ao banco antes da queda.
- A queda continua registrada como erro no log, com o correlation id. Indisponibilidade não é silêncio.
- Um driver que descreva a queda com palavras que o Laravel não conhece volta ao 500 de antes. É o lado fraco de depender de uma lista de mensagens, e é o experimento que acusa se isso acontecer com o PostgreSQL ou o MySQL da stack.
- O projeto passa a ter duas definições de conexão perdida: esta, na borda HTTP, e a `LostConnection` do pacote de mensageria, que decide o retry dos consumidores. A de mensageria não depende do Laravel e só conhece o PostgreSQL; esta precisa conhecer o MySQL do catálogo. Se as duas um dia discordarem num caso que importe, o caminho é uma definição só, no pacote, com os códigos do MySQL.

## Alternativas consideradas

- **Checar o banco antes de cada request**: uma consulta a mais em todo request, e a queda pode acontecer entre a checagem e a consulta de verdade.
- **Deixar o 500 e ensinar o BFF a tentar de novo**: o BFF não sabe se o 500 foi uma queda ou um bug, e repetir um bug só multiplica o erro.
- **Reusar já a `LostConnection` da mensageria**: ela não reconhece o MySQL, e ensinar o MySQL a ela agora mexeria no retry dos consumidores sem que nenhum deles leia um banco MySQL.
- **Circuit breaker em volta do banco**: faz sentido para uma dependência remota e lenta, como o PSP. O banco é a fonte da verdade do serviço: sem ele não existe resposta útil, e o 503 imediato já é a resposta rápida.
