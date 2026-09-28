# UC-SHP-12: Conciliar a jornada com a transportadora

| | |
|---|---|
| **Nível** | subfunção |
| **Ator principal** | Relógio (o worker `logistics-journey-reconciler`) |
| **Escopo** | Logistics (Shipping) |
| **Gatilho** | uma remessa nas mãos da transportadora passa tempo demais sem notícia (60 s no laboratório, `CARRIERS_RECONCILIATION_QUIET_SECONDS`) |

## Partes interessadas e interesses

- **Cliente**: a encomenda entregue aparece como entregue, mesmo que o aviso da transportadora tenha se perdido no caminho.
- **Operador logístico**: nenhuma remessa parada no meio da jornada à espera de alguém perceber.
- **Transportadora**: o histórico que ela já guarda basta; ela não precisa reenviar o que perdeu.

## Pré-condições

- A remessa espera a transportadora: de `ready_for_pickup` até `returning`, sem estado final.
- A coleta foi agendada com o id da remessa como referência (UC-SHP-04).

## Garantias mínimas

- Nenhum passo é aplicado duas vezes: o histórico passa pelos mesmos casos de uso do webhook, e a inbox descarta o que já entrou.
- Uma remessa é conciliada por uma cópia do worker de cada vez, e depois fica quieta até passar o prazo de novo.
- Uma transportadora fora do ar não trava a rodada: a remessa volta a ser consultada na próxima vez que ficar quieta.

## Garantias de sucesso

- Os passos que faltavam entram em ordem, cada um com o histórico, a visita e o evento na outbox, como se o webhook tivesse chegado.

## Cenário principal de sucesso

1. O sistema reserva a remessa que espera a transportadora e está sem notícia há mais tempo, desde que além do prazo, e marca o toque nela.
2. O sistema pede à transportadora a coleta da remessa, pela referência, e o histórico de eventos dela.
3. O sistema aplica cada evento do histórico, do mais antigo ao mais novo, pelo caso de uso do passo dele (UC-SHP-04 a 08).
4. Os eventos que já tinham entrado voltam como `duplicate`; os que faltavam entram, e o resultado é `caught_up`, com a contagem de passos aplicados.

## Extensões

- 1a. Nenhuma remessa quieta além do prazo: o worker espera 5 s e olha de novo.
- 2a. A transportadora não responde ou responde com erro: o resultado é `carrier_unreachable`, e a remessa fica para quando estiver quieta de novo.
- 2b. A transportadora não tem coleta para a remessa (o agendamento ainda não saiu, ou a coleta passou da retenção): histórico vazio, resultado `up_to_date`.
- 2c. Um evento do histórico não se deixa ler: ele é pulado com um warning, e os outros seguem.
- 3a. Todos os eventos já estavam na remessa: a jornada só está lenta, e o resultado é `up_to_date`.
- 3b. Um hub scan do histórico chega depois da saída para entrega: ele é `obsolete` (UC-SHP-05, 3b), e a conciliação segue para o próximo evento.
- 3c. A máquina de estados recusa um passo do histórico: os passos anteriores ficam, a conciliação para ali com `stopped` e um warning, porque uma pessoa precisa olhar.

## Variações de tecnologia

- A reserva é um `UPDATE ... WHERE id = (SELECT ... FOR UPDATE SKIP LOCKED) RETURNING`, o mesmo lease pelo `updated_at` da conciliação de pagamentos (UC-PAY-03), com o índice parcial `shipments_awaiting_carrier_idx`.
- A transportadora guarda cada evento antes de tentar o webhook, então um webhook derrubado perde a mensagem, nunca o evento (`GET /carriers/v1/pickups/{id}/events`).

## No código

- Port `ForReconcilingJourneys`, caso de uso `ReconcileJourneys`, pacote `Logistics\Shipping`. O histórico chega pelo port `ForTrackingPickups` (adapter `CarrierFakeTracking`), já traduzido em `CarrierEvent` pelo `CarrierFakeEvents`, o mesmo tradutor do webhook. Cada `CarrierEvent` se entrega ao caso de uso do passo dele pelo `applyTo`, sobre a `CarrierJourney`. O worker é o `logistics:reconcile-journeys`.
