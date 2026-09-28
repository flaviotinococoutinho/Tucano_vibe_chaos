# UC-SHP-13: Vigiar as jornadas paradas

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Relógio (o worker `logistics-stalled-journeys-watch`) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | a cada 15 min (`JOURNEYS_STALLED_WATCH_EVERY_SECONDS`), ou uma rodada sob demanda com `--once` |

A jornada assíncrona se cura sozinha quase sempre: o webhook é reenviado, o consumer tenta de novo, a conciliação (UC-SHP-12) busca o que se perdeu. O que sobra depois de tudo isso não é trabalho para mais uma tentativa, é pergunta para uma pessoa. Este caso de uso é a leitura analítica que acha essas remessas e o alerta que diz quais são e por quê.

## Partes interessadas e interesses

- **Operador logístico**: saber, sem abrir o banco, quais remessas pararam com a transportadora, desde quando, e o que a conciliação achou delas.
- **Cliente**: uma encomenda parada vira assunto de alguém antes de virar reclamação.
- **Banco da logística**: a leitura analítica não atrapalha os webhooks nem a conciliação.

## Pré-condições

- A conciliação (UC-SHP-12) grava o resultado de cada rodada em `journey_checks`.

## Garantias mínimas

- A leitura não escreve nada: é uma transação `READ ONLY`, com `statement_timeout` próprio (`JOURNEYS_STALLED_QUERY_TIMEOUT_MS`).
- O e-mail fora do ar não perde o alerta: a linha de log no nível `alert` sai antes, e a próxima rodada tenta o e-mail de novo.
- Sem o Redis, o alerta sai do mesmo jeito. Prefiro o mesmo alerta duas vezes a um alerta perdido.

## Garantias de sucesso

- Cada remessa parada além do prazo aparece no alerta, das mais antigas para as mais novas, com o status, a transportadora, a hora do último passo e o motivo, nas palavras da última rodada da conciliação.
- As mesmas remessas não voltam a encher a caixa antes da janela de repetição, e uma remessa que acabou de parar sai na hora.

## Cenário principal de sucesso

1. O sistema calcula o corte: agora menos o prazo (1 h, `JOURNEYS_STALLED_AFTER_SECONDS`).
2. O sistema lê as remessas que esperam a transportadora cujo último passo no histórico é anterior ao corte, com a última resposta da conciliação e o total.
3. O sistema monta o alerta: no assunto, o total e o corte; no corpo, uma linha por remessa, até o limite (50, `JOURNEYS_STALLED_ALERT_LIMIT`).
4. O sistema confere a impressão digital do alerta (as remessas e o total) na janela de repetição (4 h, `JOURNEYS_STALLED_ALERT_REPEAT_SECONDS`).
5. O sistema escreve o alerta no log, no nível `alert`, e manda o e-mail para a operação (`ALERTS_EMAIL_TO`).

## Extensões

- 2a. Nenhuma remessa parada: nada é alertado, e o worker registra a rodada com total zero.
- 2b. A leitura passa do `statement_timeout` ou o banco não responde: a rodada falha com um erro no log, e o worker tenta de novo depois de uma pausa (`JOURNEYS_STALLED_WATCH_FAILURE_PAUSE_MS`).
- 3a. Há mais remessas paradas que o limite: o alerta lista as mais antigas e conta as outras numa última linha.
- 4a. As mesmas remessas já foram alertadas dentro da janela: nada sai, e a rodada só registra o total no log.
- 4b. O Redis não responde: o alerta sai sem a conferência, com um warning.
- 5a. O servidor de e-mail não responde: a linha de log do alerta já saiu, e o erro do envio vai para o log. A janela de repetição só começa quando o alerta sai, então ela é desfeita, e a próxima rodada manda de novo.

## O motivo de cada remessa

A última rodada da conciliação diz por que a remessa parou, e o motivo sai no alerta:

| Última resposta em `journey_checks` | No alerta | O que costuma ser |
|---|---|---|
| nenhuma | `never compared with the carrier yet` | a conciliação ainda não chegou nela, ou está parada |
| `unknown_to_carrier` | `the carrier does not know it` | a coleta nunca foi agendada, ou o histórico passou da retenção |
| `up_to_date` | `the carrier has no news either` | a encomenda está parada de verdade, na transportadora |
| `carrier_unreachable` | `the carrier does not answer` | a transportadora está fora do ar há um bom tempo |
| `stopped` | `the state machine refused a step of the carrier history` | um passo que a máquina de estados não aceita: bug de um dos lados |
| `caught_up` | `it caught up with the carrier and stopped again` | andou com a conciliação e parou de novo |

Cada linha também diz desde quando a resposta é a mesma: `journey_checks` guarda a sequência de rodadas com o mesmo resultado, e uma resposta diferente começa outra.

## Variações de tecnologia

- O último passo vem de `shipment_transitions` e não de `shipments.updated_at`, que a conciliação toca a cada reserva. O índice `(shipment_id, occurred_at)` responde o `max` de cada remessa sem ler a tabela.
- O `count(*) OVER ()` conta antes do `LIMIT`: o total vem na mesma consulta.
- A janela de repetição é um `SET NX EX` no Redis, pelo `Cache::add`: duas cópias do worker ao mesmo tempo mandam um alerta só.
- Em produção, a leitura iria para uma réplica, e o alerta seria uma regra sobre o log (Loki, CloudWatch) ou sobre uma métrica, com o Alertmanager cuidando da repetição. No laboratório, o e-mail cai no Mailpit.

## No código

- Port `ForWatchingStalledJourneys`, caso de uso `WatchStalledJourneys`, pacote `Logistics\Shipping`. A leitura é o port `ForFindingStalledJourneys` (adapter `PostgresStalledJourneys`); o alerta é o port `ForRaisingAlerts`, com o `DeduplicatedAlerts` em volta do `LogAndMailAlerts`; as rodadas da conciliação chegam pelo port `ForRecordingJourneyChecks` (adapter `PostgresJourneyChecks`). O worker é o `logistics:watch-stalled-journeys`.
