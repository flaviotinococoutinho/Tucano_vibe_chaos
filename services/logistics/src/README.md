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

Pacotes:

- `Shipping`: a remessa e a máquina de estados inteira (`ShipmentStatus`, os guards em `Domain/Guard` e as evidências de cada transição em `Domain/Transition`), a cópia local do catálogo e o consumidor dos eventos de pedido.
- `CarrierSelection`: a corrente de regras que escolhe a transportadora (`Domain/Rule`) sobre a tabela `carriers`.
- `Shared`: os ports que todos usam (transação, inbox e outbox), o relay da outbox e a leitura dos CloudEvents que chegam do Kafka.

`Labels` (geração de etiquetas) entra com o UC-SHP-03.

Um pacote chama outro pelo port de entrada dele. O `CreateShipment` precisa de uma transportadora e declara isso no port de saída `ForChoosingCarriers` do Shipping; o adapter `CarrierSelectionChoices` chama o `ForChoosingCarriers` do CarrierSelection com valores simples. O `deptrac.yaml` garante a direção das dependências, e o teste `UseCasesAreDocumentedTest` exige a ficha de cada caso de uso em `docs/use-cases`.
