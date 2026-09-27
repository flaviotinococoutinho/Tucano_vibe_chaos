# 0001. Registrar decisões de arquitetura

- Status: aceito
- Data: 2026-09-27

## Contexto

O projeto mistura cinco runtimes, três bancos, mensageria e simuladores. Sem registro, as razões de cada escolha se perdem e o sistema começa a acumular soluções diferentes para o mesmo problema.

## Decisão

Registrar decisões relevantes como ADRs numerados em `docs/adr/`, no formato de Michael Nygard. Todo PR que muda arquitetura, contrato ou dependência estrutural traz o ADR correspondente.

## Consequências

- A história das decisões fica versionada junto com o código.
- Mudar de ideia é permitido, mas deixa rastro: um ADR novo substitui o antigo.
