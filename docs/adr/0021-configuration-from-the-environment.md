# 0021. Configurar tudo pelo ambiente, com a unidade no nome

- Status: aceito
- Data: 2026-09-28

## Contexto

Os serviços já liam endereços e segredos do ambiente, mas muita coisa que um experimento quer mexer estava no código: as pausas dos workers, os retries dos consumers, os timeouts dos clientes HTTP e da AWS, o TTL do cache do catálogo, as tentativas do job de etiqueta, os retries dos webhooks do simulador. Uma varredura achou mais de 40 desses valores espalhados por constantes e parâmetros com default.

Os nomes que existiam também não seguiam uma regra: `PAYMENT_` num lugar e `PAYMENTS_` no outro, `REDIS_TIMEOUT` sem dizer se era segundo ou milissegundo, `SERVICE_NAME` no Node e `APP_NAME` no PHP, `TRACKING_KEEP_DAYS` e `CARRIERS_RECONCILIATION_QUIET_SECONDS` para algo que é da jornada da remessa. E os `config/*.php` do Laravel traziam o esqueleto inteiro do framework (sqlite, memcached, postmark, papertrail), com umas 90 variáveis que nenhum serviço usa.

## Decisão

- Tudo o que muda entre ambientes vem do ambiente ([fator III](https://12factor.net/pt_br/config)), e cada serviço lê o ambiente num lugar só: `config/*.php`, `Config::fromEnvironment` ou `loadConfig`. O resto do código recebe os valores pelo construtor.
- Todo valor tem um padrão que serve para a stack, então o serviço sobe sem nenhuma variável.
- Serviço de apoio usa o nome da ferramenta (`DB_*`, `REDIS_*`, `KAFKA_*`, `MAIL_*`, `AWS_*`). O resto segue `<ÁREA>_<RECURSO>_<AJUSTE>_<UNIDADE>`, com a área no plural, e tempo sempre diz a unidade.
- Regra de negócio fica no código, com teste: limite de itens, visitas de entrega, tamanho de campo, layout do Snowflake.
- Os arquivos de configuração ficam só com o que o serviço usa. Um subsistema que o serviço não usa perde o arquivo inteiro, porque o Laravel 11 junta os defaults do framework de qualquer jeito.
- Uma fitness function (`scripts/check-config.py`, no `make config-check` e no CI) confere que o compose só define o que o serviço lê, que tudo o que é lido está em `docs/operations/configuration.md`, que ninguém lê o ambiente fora do lugar dele e que duração tem unidade no nome.

## Consequências

- Um experimento muda um valor com um `compose.override.yaml` e um restart do serviço, sem build.
- Renomear variável é mudança que quebra quem já tem override. Os nomes antigos saíram de uma vez, com a lista no CHANGELOG, em vez de conviverem com os novos.
- A referência não envelhece em silêncio: variável nova sem documentação, ou documentação de variável que sumiu, quebra o CI.
- Os valores acoplados continuam acoplados, só que escritos: as tentativas do job de etiqueta e o `maxReceiveCount` da fila, o timeout do job e a visibilidade da fila. A referência diz qual depende de qual.

## Alternativas consideradas

- **Arquivos de configuração por ambiente** (`config/staging.php`): é o que o fator III pede para evitar. Cada ambiente novo vira um arquivo, e o segredo acaba no repositório.
- **Manter os nomes antigos como apelido**: nenhuma quebra, mas dois nomes para a mesma coisa é exatamente a entropia que eu queria tirar.
- **Gerar a referência a partir do código**: sai sempre certa, mas perde o que mais importa nela, que é o porquê de cada valor. Preferi escrever à mão e deixar a fitness function cobrar a sincronia.
