# chaos_playground

Laboratório pessoal para estudar PHP moderno (Laravel, Lumen, Swoole), Node.js, system design e engenharia do caos, construído em torno de um e-commerce fictício com logística de entrega própria: a **Tucano**.

- **Commerce** (Laravel): pedidos, estoque, pagamentos e notificações.
- **Logistics** (Laravel): remessas, transportadoras e etiquetas, com a máquina de estados da entrega.
- **Catalog** (Lumen): catálogo com cache, no papel de serviço legado.
- **Tracking** (Swoole): posição dos entregadores em tempo real.
- **BFF** e **simulador de parceiros** (Node.js), atrás de um **Kong**, com a web em **React**.
- Kafka com ZooKeeper, PostgreSQL, MySQL, MongoDB, Redis e AWS local com Floci.

A arquitetura, as decisões e a trilha de estudo estão em [`docs/`](docs/README.md).

> Em construção. O andamento de cada parte está na tabela de status em [`docs/architecture`](docs/architecture/README.md).
