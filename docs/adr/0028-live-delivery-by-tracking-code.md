# 0028. Entrega ao vivo pelo código de rastreio

- Status: aceito
- Data: 2026-09-28

## Contexto

O [ADR 0013](0013-swoole-for-fleet-tracking.md) escolheu o Swoole para o tempo real da frota, e o [UC-TRK-03](../use-cases/UC-TRK-03-follow-delivery-live.md) descreveu o cliente acompanhando o entregador ao vivo. Nada disso existia além do esqueleto: o `tracking` recusava todo WebSocket, nenhuma peça produzia uma posição, e a web só atualizava a tela de rastreio de 5 em 5 s.

Duas coisas que o UC-TRK-03 supunha também não existem: nenhum entregador é designado a uma remessa (o despacho, UC-TRK-02, ainda não foi escrito), e o endereço do cliente não tem coordenadas.

## Decisão

- **O aparelho do entregador informa por HTTP.** `POST /v1/positions`, uma notícia por request, assinada com HMAC como os webhooks das transportadoras (`Courier-Signature`). Uma tentativa só: a próxima posição substitui a perdida.
- **O navegador acompanha por WebSocket.** `GET /v1/live?trackingCode=` com upgrade, pela rota `/api/tracking` que o Kong já tinha. Logo depois do handshake vai a última notícia do código, depois cada nova; o fim da visita fecha a conexão com `1000`, e um desligamento, com `1001`, para o cliente voltar em outro worker.
- **Pelo código de rastreio, não pelo entregador.** Sem despacho, o aparelho sabe quais encomendas leva, e o cliente sabe o código da sua. O mesmo código liga as duas pontas.
- **Só a última notícia, por 15 minutos.** O Redis guarda `delivery:<código>:last` e mais nada: nenhuma história do trajeto de um entregador.
- **Fan-out por Redis pub/sub**, como o ADR 0013 já previa: o worker que recebe a notícia a publica no canal `deliveries`, e cada worker, de cada instância, empurra para os seus próprios seguidores. No modo BASE do Swoole, um worker só alcança as conexões que ele aceitou.
- **O BFF oferece o caminho.** A tela de rastreio ganha o link `rel-live` só enquanto uma encomenda da frota própria está a caminho da porta. A web nunca monta o endereço.
- **O aparelho é simulado no partners-sim**, que já fazia o papel do mundo lá fora: uma posição por segundo, durante 20 s, do centro de distribuição até um ponto derivado do código de rastreio, porque não há geocodificação.
- **A web trata o ao vivo como um bônus.** Sem o WebSocket, ela avisa que a posição não está disponível, tenta de novo com uma espera crescente, e a tela continua se atualizando pelo polling de sempre.

O protocolo inteiro está em [`contracts/tracking`](../../contracts/tracking/README.md).

## Consequências

- A jornada da frota própria fica uns 20 s mais longa: o trajeto até a porta agora tem duração.
- O ADR 0013 continua valendo no que decide (Swoole, e o fan-out por pub/sub). Duas partes dele ficam para quando fizerem falta: o Redis GEO, que serve para achar o entregador mais perto (UC-TRK-02), e a conexão aberta do lado do entregador.
- O código de rastreio é a única credencial para acompanhar, como já era para abrir a página de rastreio. A posição só existe enquanto a encomenda está a caminho, e some 15 minutos depois da última notícia.
- Cada chamada do tracking ao Redis abre a sua conexão, porque uma conexão do phpredis pertence a uma corrotina por vez. Na escala de um laboratório, isso custa menos do que um pool que precisa lidar com conexões quebradas; o pool é o próximo passo quando a frota crescer.
- O Kong fecha uma conexão parada depois de 60 s. Com uma posição por segundo, a conexão nunca fica parada enquanto a encomenda anda.
- A desatualização (sem sinal há mais de 30 s) é decidida pela web, que compara o `at` da posição com o relógio. O tracking não precisa de temporizador por conexão.
- Uma peça nova que pode cair. Sem o tracking ou sem o Redis, a web avisa e segue pelo polling; nenhum experimento prova isso ainda, e o [mapa de modos de falha](../architecture/failure-modes.md) registra.

## Alternativas consideradas

- **WebSocket também do lado do aparelho**, como o ADR 0013 imaginava: mais realista para um aplicativo de verdade, mas uma posição por segundo cabe num POST assinado, que é mais fácil de testar e de derrubar num laboratório.
- **Server-Sent Events para o navegador**: o fluxo é de mão única e o SSE resolveria, mas o Swoole foi escolhido justamente para segurar WebSockets, e o protocolo deixa a porta aberta para o cliente responder um dia.
- **O BFF repassando o WebSocket**: manteria tudo debaixo de `/bff`, mas poria no Node milhares de conexões longas, o trabalho para o qual o Swoole foi escolhido.
- **Polling mais rápido da tela de rastreio**: cada cliente consultando o BFF e a logistics a cada segundo multiplica a carga pelo número de pessoas olhando; empurrar a notícia é o ponto.
