# Setup com Ansible

Um playbook que prepara a máquina para o laboratório e sobe a stack: confere o disco e a memória, instala o que falta, liga a VM do Docker, escreve o `.env` e roda o `make up` até tudo ficar saudável. Ele é idempotente: numa máquina pronta, não muda nada, e posso rodar de novo sem medo.

```bash
brew install ansible          # no Mac; no Linux, pipx install --include-deps ansible
make setup                    # prepara a máquina e sobe a stack
make setup-check              # só confere: a máquina e a saúde da stack no ar
```

## O que ele faz

| Papel | O que faz | Muda a máquina? |
|---|---|---|
| `preflight` | confere o sistema, a memória e o espaço livre no disco que guarda o Docker; para antes de construir qualquer coisa se faltar espaço | não |
| `toolchain` | no Mac, instala pelo Homebrew o Colima, o Docker CLI, o Compose e o jq; no Debian ou Ubuntu, instala o Docker Engine do repositório oficial, se ainda não houver um Docker | sim |
| `runtime` | liga a VM do Colima (ou o serviço do Docker no Linux) e espera o daemon responder; avisa se o Docker tem menos memória ou CPU do que a stack pede | sim |
| `project` | escreve o `APP_ENV` no `.env` e, se eu pedir, um `compose.override.yaml` com valores do laboratório | sim |
| `stack` | roda o `make doctor` e o `make up`, que constrói as imagens e espera os health checks | sim |
| `smoke` | pergunta a cada serviço, pelo Kong, se ele está pronto, e ao Mailpit se ele responde | não |

O que já existe fica como está. Um Docker Desktop, um Docker de runner de CI ou uma VM do Colima já criada não são reinstalados nem redimensionados: a VM existente sobe com as configurações dela, porque passar o tamanho de novo pode crescer o disco para sempre.

## Por que o disco vem primeiro

No Mac, a VM do Colima guarda o disco dela como um arquivo no disco do Mac, e esse arquivo cresce conforme a VM escreve. A VM pode achar que tem espaço quando o Mac já não tem nenhum. Aí toda escrita dentro dela falha com erro de I/O, e o kernel remonta o disco como somente leitura: bancos, Kafka e o próprio Docker param de gravar.

Aconteceu comigo durante um rebuild das imagens, com o Mac a 133 MB do fim. Por isso o `preflight` para abaixo de 10 GB livres (e avisa abaixo de 20), e o `make doctor` faz a mesma conta. Para devolver ao Mac o espaço liberado dentro da VM, depois de um `docker image prune`, tem o `make trim`.

## Variáveis

Todas ficam em `inventory/group_vars/all.yml`, com o valor que serve para o laboratório e um comentário. Dá para mudar uma só numa rodada com `-e`:

```bash
cd infra/ansible
ansible-playbook playbooks/setup.yml -e app_env=staging               # sobe com as flags de staging
ansible-playbook playbooks/setup.yml -e start_stack=false             # só prepara a máquina
ansible-playbook playbooks/setup.yml --tags machine                   # só ferramentas e VM
ansible-playbook playbooks/setup.yml --check --diff                   # mostra o que mudaria, sem mudar
```

| Variável | Padrão | O que faz |
|---|---|---|
| `app_env` | `local` | as flags da stack: `local`, `staging` ou `production` |
| `host_min_free_gb` e `host_recommended_free_gb` | `10` e `20` | o espaço livre mínimo para seguir, e o confortável |
| `colima_cpus`, `colima_memory_gb` e `colima_disk_gb` | `4`, `4` e `40` | o tamanho da VM, usado só quando ela ainda não existe |
| `colima_vm_type` | `vz` | o hipervisor do macOS, mais rápido que o QEMU nos Macs com Apple Silicon |
| `start_stack` e `run_smoke_checks` | `true` | se o playbook sobe a stack e confere a saúde dela no fim |
| `lab_overrides` | vazio | valores de ambiente por serviço, escritos no `compose.override.yaml` |

Um exemplo de `lab_overrides`, para ver o alerta de jornadas paradas sem esperar uma hora:

```yaml
lab_overrides:
  logistics-stalled-journeys-watch:
    JOURNEYS_STALLED_AFTER_SECONDS: "120"
```

As variáveis de cada serviço estão na [referência de configuração](../../docs/operations/configuration.md).

## Como é testado

- No CI, o `ansible-lint` roda no perfil `production`, e a parte da máquina do setup (`preflight`, `toolchain`, `runtime` e `project`) roda de verdade num Ubuntu limpo.
- O workflow `setup` (manual, em Actions) roda o playbook inteiro num runner limpo: instala, sobe as dezenas de containers e confere a saúde de cada serviço. É a prova de que alguém com uma máquina nova chega à stack no ar com um comando.
- No meu Mac, rodo o `--check --diff` antes de mexer no playbook.
