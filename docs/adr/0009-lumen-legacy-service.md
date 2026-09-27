# 0009. Lumen como serviço legado

- Status: aceito
- Data: 2026-09-27

## Contexto

O Lumen foi o micro-framework do ecossistema Laravel. A documentação oficial hoje diz que não é mais recomendado para projetos novos, e a última versão (11.2, sobre os componentes do Laravel 11) não recebe correções de segurança desde março de 2026. Mesmo assim, muita empresa ainda mantém serviços em Lumen, e entrevistas costumam perguntar sobre ele.

## Decisão

- O `catalog` usa **Lumen 11** em **PHP 8.3**, representando de propósito um serviço legado dentro de uma arquitetura moderna.
- O esqueleto é montado à mão, porque o repositório `laravel/lumen` foi arquivado na versão 10.
- A estrutura é em camadas simples, sem hexágono, como convém a um subdomínio de suporte.

## Consequências

- Mostra na prática o que muda entre Lumen e Laravel (sem *facades* e Eloquent por padrão, roteamento mais enxuto, menos *bootstrapping*).
- O serviço fica preso ao PHP 8.3 e a componentes sem suporte, um risco documentado como dívida técnica.
- A migração para Laravel fica descrita como exercício, com o que muda em rotas, providers e configuração.

## Alternativas consideradas

- **Laravel no catálogo**: mais correto para um projeto novo, mas perderíamos o exemplo de legado.
- **Slim ou Mezzio**: micro-frameworks ativos, porém fora do escopo pedido.
