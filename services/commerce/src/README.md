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

Subdomínios previstos: `Ordering`, `Inventory`, `Payments` e `Notifications`. O `deptrac.yaml` garante a direção das dependências, e o teste `UseCasesAreDocumentedTest` exige a ficha de cada caso de uso em `docs/use-cases`.
