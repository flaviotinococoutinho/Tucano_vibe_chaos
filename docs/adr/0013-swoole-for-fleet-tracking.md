# 0013. Swoole para o tempo real da frota

- Status: aceito
- Data: 2026-09-27

## Contexto

Cada entregador envia a posição a cada poucos segundos por uma conexão aberta, e clientes acompanham a entrega ao vivo. No PHP-FPM, cada conexão ocuparia um processo inteiro: o modelo *shared-nothing* não foi feito para conexões longas.

## Decisão

O serviço `tracking` usa **Swoole 6.2** em PHP 8.4: um servidor HTTP e WebSocket com corrotinas e *hooks* de I/O. As posições ficam no **Redis GEO** (`GEOADD`, `GEOSEARCH`), e o *fan-out* entre instâncias acontece por Redis pub/sub.

## Consequências

- Milhares de conexões num punhado de processos, com memória previsível.
- O estado vive entre requisições, e isso é o outro lado da moeda: variáveis estáticas, singletons e conexões precisam de cuidado para não vazar entre usuários. É o mesmo cuidado exigido pelo Laravel Octane.
- Mudança de código exige *reload* do servidor, ao contrário do FPM.

## Alternativas consideradas

- **Node.js**: faria o mesmo trabalho, mas o objetivo é mostrar PHP assíncrono.
- **OpenSwoole**: fork com API parecida e comunidade menor.
- **Laravel Reverb**: servidor WebSocket oficial do Laravel, acoplado ao ecossistema de broadcasting.
