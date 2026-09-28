# tracking

Serviço de frota em tempo real da Tucano, em PHP 8.4 com Swoole 6.2. Ele vai receber a posição GPS dos entregadores por WebSocket, guardar no Redis GEO, achar o entregador disponível mais próximo para a logística e empurrar a posição ao vivo para o cliente que acompanha a entrega.

Por enquanto é o esqueleto: um servidor de longa duração com health checks, erros no formato do commerce, logs JSON, correlation id e configuração por ambiente. As funcionalidades entram em cima dele.

## Por que Swoole

Cada entregador manda a posição a cada poucos segundos por uma conexão aberta, e cada cliente acompanha a entrega por outra. No PHP-FPM, cada conexão dessas prenderia um processo inteiro. Com Swoole, um punhado de processos segura milhares de conexões. A decisão e as alternativas estão no [ADR 0013](../../docs/adr/0013-swoole-for-fleet-tracking.md).

## O que muda em relação ao PHP-FPM

No FPM, cada request começa do zero: nada do request anterior sobra na memória. Aqui o processo fica de pé e atende milhares de requests, muitos ao mesmo tempo. Isso muda três coisas no dia a dia.

**O estado vive entre requests.** Uma propriedade estática ou um singleton com dado de request vaza para o próximo cliente, ou pior, para o request que está rodando em paralelo no mesmo worker. Por isso dado de request fica no contexto da corrotina que atende o request (`Swoole\Coroutine::getContext()`), que o Swoole destrói quando a corrotina termina. É o que a `RequestContext` faz com o correlation id. Estado de processo (config, cache de flags, o próprio kernel) pode ficar em memória, desde que não carregue nada de um request.

**Corrotinas no lugar de bloqueio.** Com `SWOOLE_HOOK_ALL`, phpredis, curl (o Guzzle do flagd), `sleep` e arquivos cedem a vez para outra corrotina em vez de travar o worker. A contrapartida: uma conexão não pode ser usada por duas corrotinas ao mesmo tempo. Cada probe do Redis abre a própria conexão, e o tráfego de GEO vai usar um pool (`Swoole\Database\RedisPool`). Cada worker também monta o próprio grafo de objetos em `workerStart`, depois do fork, para nenhum socket ser dividido entre processos.

**Código novo exige reload.** O worker carrega as classes uma vez e fica com elas. Mudou o código, reinicie o container. Com o código montado por volume, `SIGUSR1` (`docker kill -s USR1 tracking`) recicla só os workers, que carregam de novo as classes usadas depois do fork. O que o `bin/server.php` usa antes do fork (config e logger) só muda com restart.

## Endpoints

| Rota | Pelo Kong | O que faz |
|---|---|---|
| `GET /health/live` | `/api/tracking/health/live` | o processo está de pé |
| `GET /health/ready` | `/api/tracking/health/ready` | o Redis responde, com a latência |

Todo erro sai como `application/problem+json` (RFC 9457), com os mesmos campos do commerce: `type`, `title`, `status`, `detail`, `instance`, `correlationId` e, em falha de validação, `errors` por campo. Erros de domínio viram status pela categoria (`NotFound` 404, `Conflict` 409, `InvalidInput` 422, `Forbidden` 403, `Unavailable` 503). Erro 5xx nunca mostra a mensagem interna: ela fica no log, junto com o correlation id. O 405 traz o header `Allow`.

Toda rota GET também responde HEAD, como no commerce. O kernel tira o corpo da resposta e mantém o `Content-Length`, porque o Swoole mandaria o corpo mesmo num HEAD.

O mesmo servidor fala WebSocket na porta 9501. Enquanto o protocolo da frota não existe, todo pedido de upgrade passa pelo mesmo roteador do HTTP e recebe o 404 em problem+json.

## Logs e correlation id

O `X-Correlation-Id` que o Kong coloca (`uuid#counter`) volta no header da resposta e aparece em toda linha de log do request. Sem o header, o serviço gera um UUIDv7. Um valor que não seja um token curto e imprimível é trocado por um novo, para ninguém injetar lixo nos logs.

Os logs vão para o stderr, uma linha JSON por evento:

```json
{"timestamp":"2026-09-27T10:12:09.903Z","level":"debug","message":"Request handled","service":"tracking","correlation_id":"4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#42","method":"GET","path":"/health/ready","status":200,"duration_ms":8.73}
```

Com `LOG_LEVEL=debug` sai uma linha por request. Um readiness que falha deixa um `warning` (`Not ready`) com o erro de cada dependência, porque o balanceador só olha o status. Warnings e fatal errors do PHP também viram linha JSON.

## Configuração

Tudo vem de variáveis de ambiente. Os defaults servem para a stack do compose, onde Redis e flagd são acessados pelo Toxiproxy. Um valor inválido derruba o boot com uma mensagem clara, em vez de falhar no primeiro request. A lista completa (porta, workers, espera no `SIGTERM`, timeouts do Redis e do flagd) está na [referência de configuração](../../docs/operations/configuration.md#tracking).

## Como está organizado

| Caminho | O que tem |
|---|---|
| `bin/server.php` | sobe o `Swoole\WebSocket\Server` e registra os callbacks, que só traduzem objetos e delegam |
| `src/Platform/Http` | kernel, roteador, problem details, correlation id e a ponte com os objetos do Swoole |
| `src/Platform/Health` | liveness, readiness e o check do Redis |
| `src/Platform/Logging` | formatter JSON e o processor do correlation id |
| `src/Platform/CompositionRoot.php` | monta o grafo de um worker, incluindo `Flagd::connect` com `InMemoryFlagCache` |
| `tests/Feature/ServerTest.php` | sobe o `bin/server.php` de verdade e conversa com ele por TCP |

O código de negócio vai entrar em pacotes por subdomínio ao lado de `Platform` (o `Tracking\Watch` do UC-TRK-03, por exemplo), cada um com o hexágono das [convenções](../../docs/engineering/conventions.md).

## Rodando os checks

O Swoole só existe na imagem do serviço, então os checks rodam dentro dela, na rede da stack. O `make up` constrói a imagem, e depois:

```bash
make check s=tracking
```

`composer check` roda Pint, PHPStan no nível 8 (com os stubs do `swoole/ide-helper`) e PHPUnit. Os testes do grupo `integration` precisam do Redis; sem ele, use `vendor/bin/phpunit --exclude-group integration`.

## Decisões do runtime

- **Modo BASE**: cada worker é dono das conexões que aceita. No SIGTERM ele termina os requests em andamento e entrega as respostas antes de sair (`max_wait_time` de 5 segundos). No modo PROCESS o processo principal fecha todas as conexões na hora, e testei isso: o request em andamento termina, mas a resposta se perde. O fan-out de WebSocket entre workers vai pelo Redis pub/sub, que já é o caminho entre instâncias.
- **`STOPSIGNAL SIGTERM`**: a imagem base herda `SIGQUIT` do PHP-FPM. O Swoole ignora esse sinal, então o `docker stop` esperava 10 segundos e matava o servidor com SIGKILL.
- **Swoole sem io_uring**: o `install-php-extensions` compila o Swoole 6.2 com io_uring no Alpine 3.24, e o perfil seccomp padrão do Docker bloqueia essas chamadas. O servidor nem sobe (`Operation not permitted`). A imagem usa `IPE_SWOOLE_WITHOUT_IOURING=1`.
- **Primeiro build demorado**: o `install-php-extensions` compila o cliente do Firebird 5 para o `pdo_firebird` do Swoole, e não achei opção para desligar. São uns 8 minutos na primeira vez; depois a camada fica em cache.
- **Readiness só olha o Redis**: flag tem fallback no código, então flagd fora do ar não tira o serviço do balanceamento.
