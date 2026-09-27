# 0002. Serviços por subdomínio, sem microsserviços por entidade

- Status: aceito
- Data: 2026-09-27

## Contexto

O objetivo é estudar sistemas distribuídos sem cair no anti-padrão de microsserviços prematuros, e tudo precisa caber numa VM de 4 GB.

## Decisão

Um serviço por grande área de negócio, cada um com um motivo técnico para existir:

- `commerce` (Laravel): Ordering, Inventory, Payments e Notifications juntos, como um monólito modular;
- `logistics` (Laravel): Shipping, Carrier Selection e Labels;
- `catalog` (Lumen): leitura intensa e cache;
- `tracking` (Swoole): conexões de longa duração;
- `bff` e `partners-sim` (Node): I/O concorrente e simulação.

Dentro de cada serviço, os subdomínios são pacotes separados que só conversam por ports ou eventos. Assim, extrair um deles no futuro é mover uma pasta, não reescrever o sistema.

## Consequências

- A saga entre commerce e logistics é distribuída de verdade (Kafka), o que gera material para os experimentos de caos.
- Dentro do commerce, pedido, estoque e pagamento compartilham a mesma transação quando precisam, com ACID em vez de compensação.
- Há duas aplicações Laravel com o mesmo esqueleto, e o shared kernel evita duplicar o que é comum.

## Alternativas consideradas

- **Microsserviço por contexto** (orders, payments, inventory, shipments...): mais pontos de falha que conceitos novos, e memória insuficiente para rodar tudo.
- **Monólito único**: não exercitaria mensageria, consistência eventual nem falhas de rede entre serviços.
