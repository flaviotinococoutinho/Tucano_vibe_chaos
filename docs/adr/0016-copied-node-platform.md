# 0016. Plataforma Node copiada, não compartilhada

- Status: aceito
- Data: 2026-09-27

## Contexto

O `bff` e o `partners-sim` precisam da mesma cola com o Fastify: correlation id, logs, problem details, `DomainError` e health checks. No PHP, código assim vira pacote em `packages/php` e entra nos serviços por path repository do Composer.

No Node o mesmo caminho esbarra no type stripping ([ADR 0014](0014-node-for-bff-and-partners.md)). Um pacote local instalado com `file:` vira symlink, o Node segue o symlink até o caminho real e, de lá, não encontra o `node_modules` do serviço, então o `import 'fastify'` do pacote falha. Com `--preserve-symlinks` o import funciona, mas aí o arquivo passa a estar dentro de `node_modules`, e o Node se recusa a executar `.ts` ali.

## Decisão

- A pasta `src/platform/` existe nos dois serviços, com o mesmo conteúdo.
- Os jobs dos dois serviços na CI rodam `diff -r` entre as cópias e falham se elas divergirem. Mudança na plataforma entra nos dois serviços no mesmo PR.
- Se aparecer um terceiro serviço Node, a plataforma vira pacote de um npm workspace na raiz, e aí a decisão é revista.

## Consequências

- Cada serviço mantém o próprio `package-lock.json` e uma imagem independente, sem manifesto na raiz do repositório.
- A duplicação é pequena (seis arquivos) e vigiada: quem mexe em uma cópia descobre a outra pelo erro da CI, não em produção.
- O `partners-sim` faz o papel de empresas de fora da Tucano. Não depender de um pacote interno combina com esse papel.

## Alternativas consideradas

- **npm workspaces na raiz**: resolve o compartilhamento, mas cria um manifesto e um lockfile para o repositório inteiro, e cada imagem Node passa a copiar os manifestos de todos os workspaces. É caro para seis arquivos.
- **Pacote compilado para JavaScript**: roda dentro de `node_modules`, mas traz de volta a etapa de build que o ADR 0014 tirou.
