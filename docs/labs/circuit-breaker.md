# Laboratório: circuit breaker no pagamento

O PayFake fica lento. O que acontece com o checkout?

Sem proteção, cada pagamento espera o timeout inteiro, e cada espera prende um filho do PHP-FPM. Com seis filhos por container, seis clientes pagando ao mesmo tempo derrubam o commerce inteiro, inclusive quem só queria ver um pedido. O circuit breaker corta essa cadeia: depois de algumas falhas seguidas, o commerce para de chamar o PSP por um tempo e responde na hora.

## Como o breaker funciona

| Estado | O que acontece | Como sai dele |
|---|---|---|
| fechado | as chamadas passam; falhas são contadas numa janela de 30 s | 5 falhas na janela abrem o circuito |
| aberto | nenhuma chamada sai; o pagamento responde `503` com `Retry-After` | depois de 20 s, fica meio aberto |
| meio aberto | uma chamada de teste passa, as outras esperam | sucesso fecha; falha abre de novo |

Só conta como falha o que diz algo sobre a saúde do PSP: timeout, erro de rede e resposta 5xx. Uma recusa (4xx) prova que o PSP está de pé.

O estado mora no Redis, nas chaves `circuit:payfake:*`. No Java (Resilience4j, por exemplo) o estado fica na memória do processo, porque o processo vive. No PHP-FPM cada request começa do zero, então a contagem de falhas precisa morar fora dele; no Redis, todo filho de toda instância enxerga o mesmo circuito. Se o próprio Redis cair, o breaker deixa as chamadas passarem: um breaker quebrado não pode derrubar o serviço.

## O experimento

Latência de 3 s na resposta do PayFake, pelo Toxiproxy, com o timeout do commerce em 2 s:

```bash
curl -s -X POST localhost:8474/proxies/payfake/toxics \
  -d '{"name":"slow","type":"latency","attributes":{"latency":3000}}'
# sete pagamentos, cada um de um pedido novo
curl -s -X DELETE localhost:8474/proxies/payfake/toxics/slow
```

| Pagamento | Resposta | Tempo |
|---|---|---|
| 1 a 5 | `202`, pagamento pendente | 2,03 s cada: o timeout |
| 6 e 7 | `503` com `Retry-After: 20` | 5 a 7 ms: nada saiu do commerce |
| depois de 21 s, sem o toxic | `202` | 40 ms: a chamada de teste passou e o circuito fechou |

O log mostra as duas pontas: `Circuit payfake opened for 20 s: 5 failures in 30 s` e, depois, `Circuit payfake closed`.

## O que ficou pendente

Os cinco primeiros pagamentos terminaram `pending` e sem id de cobrança. A latência atrasou só a resposta: as cobranças chegaram ao PayFake e foram processadas. O commerce não sabe disso, e esse é o caso clássico de desfecho desconhecido. Quem resolve é o webhook, que carrega o id do pagamento como referência, ou uma nova tentativa do cliente com a mesma chave, que reenvia a cobrança com o mesmo id e recebe de volta a que já existia.

## Outra forma de deixar o PSP lento

A flag `chaos.commerce.payment-gateway-latency-ms` injeta a espera dentro do próprio adapter, sem mexer na rede. A espera conta contra o mesmo timeout de 2 s: com `very-slow` (3000 ms) o adapter desiste antes de chamar o PSP, e o breaker abre do mesmo jeito. O Toxiproxy mostra o problema na rede, que é onde ele acontece de verdade; a flag serve para ligar o caos num ambiente sem Toxiproxy.
