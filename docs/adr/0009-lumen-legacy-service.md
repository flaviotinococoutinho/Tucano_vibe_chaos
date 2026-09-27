# 0009. Lumen como serviço legado

- Status: aceito
- Data: 2026-09-27

## Contexto

O Lumen foi o microframework do ecossistema Laravel. A documentação oficial hoje diz que ele não é mais recomendado para projetos novos, e a última versão (11.2, sobre os componentes do Laravel 11) não recebe correções de segurança desde março de 2026. Mesmo assim, muita empresa ainda mantém serviços em Lumen, e entrevistas costumam perguntar sobre ele.

## Decisão

- O `catalog` usa Lumen 11 em PHP 8.3 e representa, de propósito, um serviço legado dentro de uma arquitetura moderna.
- O esqueleto é montado à mão, porque o repositório `laravel/lumen` foi arquivado na versão 10.
- A estrutura é em camadas simples, sem hexágono, como convém a um subdomínio de suporte.

## Consequências

- Mostra na prática o que muda entre Lumen e Laravel (facades e Eloquent desligados por padrão, roteamento mais enxuto, menos bootstrapping).
- O serviço fica preso ao PHP 8.3 e a componentes sem suporte, um risco registrado como dívida técnica.
- A migração para Laravel fica descrita como exercício, com o que muda em rotas, providers e configuração.

## Alternativas consideradas

- **Laravel no catálogo**: mais correto para um projeto novo, mas eu perderia o exemplo de legado.
- **Slim ou Mezzio**: microframeworks ativos, porém fora do escopo do projeto.
