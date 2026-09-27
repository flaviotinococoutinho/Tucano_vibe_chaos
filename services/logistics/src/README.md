# src

Código de negócio do logistics, organizado por subdomínio (package-by-feature). Cada pasta é um pacote com o hexágono completo dentro:

```text
src/<Subdomínio>/
├── Domain/                 agregados, value objects, eventos e regras
├── Application/
│   ├── Port/Driving/       o que o mundo pode pedir (ForCreatingShipments)
│   ├── Port/Driven/        o que a aplicação precisa (ForStoringShipments)
│   └── UseCase/            implementações dos ports de entrada (CreateShipment)
└── Adapter/
    ├── Driving/            HTTP, console, webhooks e consumidores de mensagens
    └── Driven/             banco, Kafka, S3, SQS e APIs das transportadoras
```

Subdomínios previstos: `Shipping` (a remessa e sua máquina de estados), `CarrierSelection` (a corrente de regras que escolhe a transportadora) e `Labels` (geração de etiquetas). O `deptrac.yaml` garante a direção das dependências, e o teste `UseCasesAreDocumentedTest` exige a ficha de cada caso de uso em `docs/use-cases`.
