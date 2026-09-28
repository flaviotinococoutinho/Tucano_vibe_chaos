# 0025. Experimentos de caos como código

- Status: aceito
- Data: 2026-09-28

## Contexto

Os laboratórios em `docs/labs` contam experimentos de verdade, com números medidos e o que mudou no código por causa deles. Mas cada experimento morava em sequências de `curl` dentro de um markdown. Repetir dependia de mim: copiar os comandos na ordem certa, olhar a resposta certa na hora certa e lembrar de tirar a falha no final. A hipótese estava no texto, não em nada que um computador conferisse, e um experimento esquecido no meio deixava a stack quebrada para o próximo.

Para um projeto que quer ser prova de conceito da engenharia do caos, a pergunta "o sistema aguenta essa falha?" precisa ter uma resposta que qualquer pessoa reproduz com um comando.

## Decisão

- Os experimentos principais viram arquivos do [Chaos Toolkit](https://chaostoolkit.org) em `chaos/experiments`: um estado estável conferido antes da falha e de novo com ela ativa, o método que provoca a falha, e os rollbacks.
- As falhas usam as alavancas que o projeto já tinha, por HTTP: toxics e cortes no Toxiproxy, e os controles `/_chaos` do partners-sim. Nada de acesso ao Docker ou ao banco de dentro do experimento.
- As sondas moram numa extensão pequena, `chaos/tucano_chaos`. Elas compram na loja do jeito que a web compra, seguindo os links e as ações das telas do BFF, e respondem `true` ou `false`, escrevendo no log o que mediram.
- O Chaos Toolkit roda num container com a versão fixa (`chaostoolkit` 1.21.1 e `chaostoolkit-lib` 1.45.1), no perfil `tools` do compose, pela rede `edge`. `make experiment e=<nome>` roda um experimento; os rollbacks rodam sempre, até quando a hipótese falha.
- O CI confere que todo experimento é válido para o Chaos Toolkit, o que inclui importar as sondas e bater a assinatura de cada função. Rodar os experimentos exige a stack inteira no ar, então o CI não os roda a cada PR.

## Consequências

- Uma hipótese vira um artefato revisável num PR, e uma execução vira um diário (`chaos/results/<nome>.json`) que diz o que foi conferido, quando e com que resultado.
- O experimento conversa com o sistema pela mesma porta que o cliente. Se o fluxo da loja mudar, as sondas seguem os links novos sem mudar uma linha, e se a loja quebrar, o experimento quebra junto, que é o que se quer.
- Os limites das hipóteses (1 s para pagar, 6 s para uma tela, 100 s para um desfecho) dependem de configuração: o prazo do BFF, o timeout do commerce para o PSP, o silêncio que a conciliação espera. Quem muda uma dessas variáveis precisa rodar o experimento de novo, e o README da pasta diz qual experimento depende de qual número.
- O projeto ganha Python, numa pasta só e sem nada no Mac: a ferramenta e as sondas moram na imagem e na pasta montada.
- Custa tempo de execução: o experimento dos webhooks perdidos leva mais de um minuto, porque é esse o tempo que a conciliação espera antes de perguntar ao PSP.

## Alternativas consideradas

- **Scripts de shell**: rápidos de escrever, mas sem a noção de hipótese, de veredito e de rollback garantido. Cada script reinventaria essas três coisas do seu jeito.
- **Um executor meu**: daria para escrever em cem linhas, mas seria uma ferramenta a mais para manter e explicar, sem o vocabulário que quem já conhece engenharia do caos reconhece.
- **Chaos Mesh ou LitmusChaos**: fortes, mas presos ao Kubernetes, e este projeto roda num Docker Compose.
- **Serviços pagos de caos**: fora de questão para um laboratório aberto, e prenderiam o experimento a um fornecedor.
- **k6 com falhas**: ótimo para carga, e deve entrar para isso. Mas carga responde "quanto aguenta", e um experimento de caos responde "o que acontece quando isto quebra".
