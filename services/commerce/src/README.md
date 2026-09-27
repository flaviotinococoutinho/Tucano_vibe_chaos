# src

Código de negócio do commerce, organizado por subdomínio (package-by-feature). Cada pasta é um pacote com o hexágono completo dentro:

```text
src/<Subdomínio>/
├── Domain/                 agregados, value objects, eventos e regras
├── Application/
│   ├── Port/Driving/       o que o mundo pode pedir (ForPlacingOrders)
│   ├── Port/Driven/        o que a aplicação precisa (ForStoringOrders)
│   └── UseCase/            implementações dos ports de entrada (PlaceOrder)
└── Adapter/
    ├── Driving/            HTTP, console e consumidores de mensagens
    └── Driven/             banco, Kafka, S3, APIs de parceiros
```

Pacotes: `Ordering` (pedidos), `Inventory` (estoque) e `Shared` (os ports que todos usam: transação, outbox e idempotência). `Payments` e `Notifications` entram com as próximas funcionalidades.

Um pacote chama outro pelo port de entrada dele. O `PlaceOrder` precisa de estoque e declara isso no port de saída `ForReservingStock` do Ordering; o adapter desse port chama o `ForReservingStock` do Inventory. Hoje os dois rodam no mesmo processo; se o Inventory virar um serviço, só o adapter muda. O `deptrac.yaml` garante a direção das dependências, e o teste `UseCasesAreDocumentedTest` exige a ficha de cada caso de uso em `docs/use-cases`.
