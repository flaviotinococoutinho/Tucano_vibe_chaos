# 0015. Feature flags com OpenFeature e flagd

- Status: aceito
- Data: 2026-09-27

## Contexto

Preciso de flags para três usos: liberar funcionalidades por ambiente (local, staging, production), trocar comportamento operacional sem deploy e ligar falhas nos experimentos de caos. Tudo tem que ser privado (sem SaaS e sem expor flags ao navegador), versionado e igual para PHP e Node.

## Decisão

- A API de avaliação é o **OpenFeature** em todos os serviços.
- O servidor é o **flagd v0.17**, com as definições em `infra/flags/environments/<APP_ENV>.flagd.json`, validadas no CI contra o JSON Schema oficial.
- O flagd serve uma cópia de runtime do arquivo do ambiente, criada a cada `make up`. O painel de caos edita essa cópia; ninguém edita o arquivo versionado em runtime.
- Flags de caos e de laboratório não existem no arquivo de produção, e o código as trata como desligadas quando `APP_ENV=production`.
- O PHP avalia remotamente (HTTP) com cache curto; o Node avalia in-process.

Os detalhes e o catálogo de flags estão em [feature-flags.md](../architecture/feature-flags.md).

## Consequências

- Trocar o flagd por outro fornecedor compatível com OpenFeature não muda o código dos serviços.
- Flags viram código revisado em PR, com histórico no git.
- Overrides de caos são efêmeros e somem no próximo `make up`.
- O flagd vira dependência de runtime. Os defaults seguros no código garantem que a queda dele não derruba nada, e isso tem experimento de caos próprio (proxy `flagd` no Toxiproxy).

## Alternativas consideradas

- **Unleash, Flagsmith, GrowthBook** self-hosted: têm interface web, mas pedem banco e mais memória, e prendem o código ao SDK do fornecedor.
- **Flags em variáveis de ambiente**: sem targeting e sem mudança em runtime.
- **Duas fontes no flagd (base + overrides)**: descartado depois de testar, porque remover uma chave da fonte de cima apaga a flag do servidor (`FLAG_NOT_FOUND`) em vez de voltar ao valor base.
