# 0029. Nenhuma release sem os experimentos num runner limpo

- Status: aceito
- Data: 2026-09-28

## Contexto

O [ADR 0025](0025-chaos-experiments-as-code.md) transformou os laboratórios em experimentos do Chaos Toolkit, mas o CI só confere se cada arquivo é um experimento válido: rodar exige a stack inteira no ar, e isso não cabe em cada PR. Os experimentos rodavam na minha máquina, quando eu lembrava.

Uma execução na minha máquina mostrou o limite disso. O `tracking-without-its-copy` desviou de novo, com a página pendurada até o BFF desistir, depois de já ter passado. Não era regressão: a imagem da logistics que estava de pé tinha sido construída antes de uma correção entrar na branch. Uma stack montada à mão pode esconder um problema, e pode também inventar um que não existe.

Ao mesmo tempo, o workflow `setup` já provava que um runner limpo do GitHub chega a uma stack saudável com um comando só, em uns 15 minutos.

## Decisão

- Um workflow `stability`: um runner Ubuntu limpo, o setup com Ansible, e depois todos os experimentos de `chaos/experiments`, um depois do outro, contra a mesma stack.
- Um experimento que desvia reprova o job, mas não interrompe os outros, para uma execução mostrar tudo o que caiu. Os diários e os logs viram artefato da execução.
- Ele roda em todo PR para a `main`, que é por onde as releases passam, toda segunda-feira, para pegar o que envelhece sozinho (imagens base, dependências), e à mão.

## Consequências

- A `main` só recebe o que manteve todos os estados estáveis numa máquina que ninguém preparou.
- Um PR de release leva uns 25 minutos a mais para ficar verde.
- Os experimentos do rastreio compram canecas no runner; a stack é nova a cada execução, então o estoque de 200 nunca acaba.
- O workflow ainda não é obrigatório no merge: isso é uma regra de proteção da branch, uma configuração do repositório, e não um arquivo dele.
- Um experimento novo entra no portão sozinho, pelo simples fato de existir na pasta.

## Alternativas consideradas

- **Rodar a bateria em todo PR para o `develop`**: seriam 25 minutos em cada mudança, inclusive nas de texto. A validação barata do ADR 0025 continua em todo PR.
- **Confiar na execução local**: é o que a imagem velha da logistics mostrou não ser suficiente.
- **Rodar toda noite no `develop`**: pegaria um desvio mais cedo, e pode entrar depois; a execução semanal e a do PR de release já cobrem o que vai para a `main`.
