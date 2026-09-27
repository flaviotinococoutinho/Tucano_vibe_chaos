# 0003. Package-by-feature com hexágono e casos de uso de Cockburn

- Status: aceito
- Data: 2026-09-27

## Contexto

O padrão do Laravel organiza o código por tipo técnico (`app/Models`, `app/Http/Controllers`). Isso espalha cada funcionalidade por dezenas de pastas e empurra o domínio para dentro do Eloquent.

## Decisão

- O código de negócio fica em `src/<Subdomínio>/`, um pacote por feature (package-by-feature).
- Dentro de cada pacote vale a Arquitetura Hexagonal de Alistair Cockburn: `Domain`, `Application` (com `Port/Driving`, `Port/Driven` e `UseCase`) e `Adapter` (`Driving` e `Driven`).
- Os ports seguem a convenção de nomes de Cockburn: `For` + gerúndio + substantivo (`ForPlacingOrders`, `ForStoringShipments`).
- Cada caso de uso é documentado no formato *fully dressed* em `docs/use-cases/` e marcado no código com `#[UseCase('UC-...')]`.
- O Deptrac garante a direção das dependências: `Adapter → Application → Domain`, e o domínio não conhece o framework.

## Consequências

- Uma funcionalidade inteira cabe numa pasta, e apagar uma feature é apagar um diretório.
- Os testes do domínio e dos casos de uso rodam sem framework e sem banco.
- Há mais arquivos pequenos do que no Laravel "padrão". Nos subdomínios de suporte (catálogo), usamos camadas simples para não pagar essa cerimônia à toa.

## Alternativas consideradas

- **Package-by-layer do Laravel**: familiar, mas acopla o domínio ao framework.
- **Clean Architecture com nomes do Uncle Bob** (`Interactor`, `Presenter`): equivalente em essência; preferimos o vocabulário de Cockburn porque ele também dá o método para escrever os casos de uso.
