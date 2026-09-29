# Como contribuir

O projeto usa **Git Flow como referência**, com um caminho curto para documentação e manutenção. **Conventional Commits** continuam sendo a convenção dos commits e uma recomendação para títulos de PR. O histórico precisa explicar a evolução do sistema, e nenhuma mudança entra sem passar pelo CI.

## Branches

| Branch | Para quê | Nasce de | Volta para |
|---|---|---|---|
| `main` | base das versões publicadas e da manutenção | - | - |
| `develop` | integração do que já está pronto | `main` | `main`, via `release/*` |
| `feat/<escopo>` | funcionalidade nova | `develop` | `develop` |
| `fix/<escopo>` | correção de algo que ainda não foi liberado | `develop` | `develop` |
| `chore/<escopo>` | manutenção, build, dependências | `develop` ou `main` | branch de origem |
| `docs/<escopo>` ou `ci/<escopo>` | documentação ou automação de CI | `develop` ou `main` | branch de origem |
| `release/<x.y.z>` | estabilizar e fechar uma versão | `develop` | `main` e depois `develop` |
| `hotfix/<escopo>` | correção urgente do que já está na `main` | `main` | `main` e depois `develop` |

Prefira nomes curtos e claros, como `feat/shipment-state-machine`, `docs/objetivo-do-laboratorio` ou `ci/PR-policy`. Inglês e kebab-case são sugestões. Para `develop`, qualquer nome de branch válido no Git é aceito, inclusive nomes gerados por ferramentas.

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
- O título deve explicar a mudança, em português ou inglês. Conventional Commits são recomendados, mas opcionais: `docs: explica o propósito do laboratório` e `Explica o propósito do laboratório` são aceitos. O CI recusa apenas títulos vazios ou compostos só por espaços; fora da convenção, emite um aviso informativo sem falhar.
- O check `ci-ok` precisa estar verde para o merge.
- O merge é feito com merge commit (equivalente ao `--no-ff` do Git Flow) para preservar a história de cada branch.
- A branch é apagada automaticamente depois do merge.

O workflow `pr-policy` valida origem e destino:

| Destino | Origens permitidas |
|---|---|
| `develop` | qualquer branch, incluindo `main` para back-merge |
| `main` | `release/x.y.z`, `hotfix/*`, `docs/*`, `chore/*` e `ci/*` |

Use o caminho direto para a `main` em mudanças de documentação, manutenção ou CI, partindo da própria `main`. Funcionalidades seguem pela `develop` e depois por uma release. O prefixo identifica a intenção, mas não substitui a revisão do diff. Depois de integrar manutenção na `main`, faça o back-merge para `develop` para manter as branches alinhadas. Não é preciso criar uma tag para cada ajuste de texto; a publicação de uma versão continua seguindo o fluxo de release.

Essa flexibilidade não desativa `ci-ok`, o lint dos workflows nem o workflow `stability`, que continua rodando em todo PR para a `main`.

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

O PR de release roda, além do CI de sempre, o workflow `stability`: a stack sobe num runner limpo e todos os experimentos de caos precisam manter o estado estável ([ADR 0029](docs/adr/0029-no-release-without-the-experiments.md)). Uns 25 minutos a mais, e a `main` só recebe o que aguentou as falhas numa máquina que ninguém preparou.

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
