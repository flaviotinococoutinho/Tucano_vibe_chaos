# Como contribuir

O projeto segue **Git Flow** com **Conventional Commits**. O histórico precisa contar a evolução do sistema sem ruído, e nenhuma mudança entra sem passar pelo CI.

## Branches

| Branch | Para quê | Nasce de | Volta para |
|---|---|---|---|
| `main` | o que foi liberado; cada merge vira uma tag `vX.Y.Z` | - | - |
| `develop` | integração do que já está pronto | `main` | `main`, via `release/*` |
| `feat/<escopo>` | funcionalidade nova | `develop` | `develop` |
| `fix/<escopo>` | correção de algo que ainda não foi liberado | `develop` | `develop` |
| `chore/<escopo>` | infraestrutura, build, docs, dependências | `develop` | `develop` |
| `release/<x.y.z>` | estabilizar e fechar uma versão | `develop` | `main` e depois `develop` |
| `hotfix/<escopo>` | correção urgente do que já está na `main` | `main` | `main` e depois `develop` |

Nomes curtos, em inglês e em kebab-case: `feat/shipment-state-machine`, `fix/outbox-retry`, `chore/local-infra`.

```mermaid
gitGraph
  commit id: "initial commit"
  branch develop
  checkout develop
  branch feat/catalog
  commit id: "feat(catalog): list products"
  checkout develop
  merge feat/catalog
  branch release/0.1.0
  commit id: "chore(release): 0.1.0"
  checkout main
  merge release/0.1.0 tag: "v0.1.0"
  checkout develop
  merge main
  checkout main
  branch hotfix/label-url
  commit id: "fix(logistics): label url"
  checkout main
  merge hotfix/label-url tag: "v0.1.1"
  checkout develop
  merge main
```

## Commits

Conventional Commits, curtos, em inglês e no imperativo. Corpo só quando acrescenta contexto.

```text
feat(logistics): add carrier selection chain
fix(commerce): release stock when payment fails
chore: bump kafka to 3.9.2
test(shipping): cover failed delivery attempts
```

Tipos aceitos: `feat`, `fix`, `chore`, `docs`, `test`, `refactor`, `perf`, `ci`, `build`, `revert`.

## Pull requests

- `main` e `develop` são protegidas: tudo entra por PR.
- O título do PR segue Conventional Commits (o CI valida).
- O check `ci-ok` precisa estar verde para o merge.
- O merge é feito com merge commit (equivalente ao `--no-ff` do Git Flow) para preservar a história de cada branch.
- A branch é apagada automaticamente depois do merge.

O workflow `pr-policy` valida origem e destino:

| Destino | Origens permitidas |
|---|---|
| `develop` | `feat/*`, `fix/*`, `chore/*`, `release/*`, `hotfix/*` e `main` (back-merge) |
| `main` | `release/x.y.z` e `hotfix/*` |

### Aprovação

O GitHub não permite aprovar o próprio PR. Enquanto eu for o único autor, o portão de qualidade é o CI obrigatório mais o checklist de definition of done do template. Quando entrar mais alguém, as rulesets passam a exigir uma aprovação e a revisão do `CODEOWNERS`.

## Versionamento e cadência

- Versões seguem **SemVer**: `MAJOR.MINOR.PATCH`. Enquanto a API não estabiliza, as versões ficam na série `0.x`.
- Cada entrega que cumpre o definition of done fecha uma release minor (`0.2.0`, `0.3.0`...), para a `develop` não acumular trabalho pronto e parado.
- Correção de algo já liberado gera uma versão patch (`0.3.1`) via `hotfix/*`.
- Tags `v*` são imutáveis: uma ruleset impede mover ou apagar uma tag publicada.
- Ao publicar a tag, o workflow `release` cria a GitHub Release com a seção correspondente do `CHANGELOG.md`.
- Branches são apagadas no merge. Localmente, `git fetch --prune` seguido de `git branch --merged develop | grep -vE '^\*|main|develop' | xargs -r git branch -d` remove as branches já integradas.

## Release

```bash
git switch develop && git pull
git switch -c release/0.2.0
# mover "Unreleased" para "0.2.0" no CHANGELOG.md e ajustar o que faltar
git commit -am "chore(release): 0.2.0"
gh pr create --base main --title "chore(release): 0.2.0"
```

Depois do merge na `main`, a tag sai direto da `origin/main`, sem trocar de branch:

```bash
git fetch origin
git tag -a v0.2.0 origin/main -m "v0.2.0" && git push origin v0.2.0   # dispara o workflow de release
gh pr create --base develop --head main --title "chore: back-merge v0.2.0"
```

Trocar o working tree para uma `main` desatualizada apaga os arquivos que ela ainda não tem, e o `pull` recria esses arquivos em seguida. Os containers da stack que montam esses arquivos perdem o mount no meio do caminho (veja [problemas comuns](docs/operations/local-environment.md#problemas-comuns)).

## Hotfix

Mesmo fluxo da release, mas partindo da `main` e subindo só a versão patch (`v0.2.1`). Depois do merge, a `main` volta para a `develop` pelo back-merge.

## Definition of Done

- testes passando, lint e análise estática sem erros;
- documentação e diagramas atualizados quando a mudança mexe em arquitetura ou contratos;
- ADR registrado para decisões que alguém vai questionar daqui a seis meses;
- `CHANGELOG.md` atualizado na seção `Unreleased`.

As convenções de código estão em [`docs/engineering/conventions.md`](docs/engineering/conventions.md).
