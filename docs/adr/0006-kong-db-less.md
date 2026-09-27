# 0006. Kong 3.9 OSS em modo DB-less

- Status: aceito
- Data: 2026-09-27

## Contexto

Precisamos de um API gateway de verdade na borda: roteamento, rate limit distribuído, correlation id, cache e health checks. A partir da 3.10 o Kong passou a ser distribuído só como Enterprise, e a **3.9.3** é a última versão open source, ainda recebendo correções.

## Decisão

- Usar `kong:3.9.3` em modo **DB-less**: toda a configuração vive em `infra/kong/kong.yml` (`_format_version: "3.0"`), versionada e validada no CI com `kong config parse`.
- Plugins: `correlation-id`, `rate-limiting` com política `redis` (limite compartilhado entre instâncias), `proxy-cache` no catálogo, `request-size-limiting` e `prometheus`.
- Os upstreams usam health checks passivos, o que dá um *circuit breaking* na borda.

## Consequências

- Configuração declarativa é GitOps puro: o que está no repositório é o que roda.
- O Kong Manager abre em modo somente leitura, porque em DB-less não há onde gravar.
- Travar na última versão OSS é uma decisão consciente, e reavaliar alternativas (APISIX, Envoy) fica registrado como estudo.

## Alternativas consideradas

- **Kong com PostgreSQL**: mais uma dependência sem ganho para o laboratório.
- **nginx puro como gateway**: não tem rate limit distribuído nem health check ativo sem módulos pagos.
