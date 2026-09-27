# 0011. Máquinas de estados com enum e guards em cadeia

- Status: aceito
- Data: 2026-09-27

## Contexto

Pedidos, pagamentos e remessas têm ciclo de vida. Modelar isso com booleanos (`is_paid`, `delivered`) cria combinações impossíveis e espalha validações pelo código.

## Decisão

- O estado é um único campo: backed `enum` no PHP, coluna com `CHECK` no banco e discriminated union no TypeScript.
- As transições permitidas ficam numa tabela explícita (`match` exaustivo sobre o enum).
- As regras que dependem de dados (comprovante, número de tentativas, etiqueta anexada) são guards encadeados em Chain of Responsibility.
- Toda transição aplicada gera uma linha de histórico e um evento de domínio.

Os diagramas e as regras estão em [state-machines.md](../architecture/state-machines.md).

## Consequências

- Estados inválidos deixam de ser representáveis, e a máquina inteira cabe numa tela.
- Cada guard é uma classe pequena e testável isoladamente, o que segue as regras de Object Calisthenics.
- Não uso biblioteca de workflow. Se as regras crescerem muito, `symfony/workflow` é o próximo passo natural.

## Alternativas consideradas

- **Padrão State do GoF** (uma classe por estado): bom quando cada estado tem muito comportamento próprio; aqui a variação está nas transições, não nos estados.
- **`symfony/workflow` e `spatie/laravel-model-states`**: prontas, mas acoplam o domínio a uma biblioteca.
