# 0030. Cada cliente só vê os próprios pedidos

- Status: aceito
- Data: 2026-09-29

## Contexto

A loja não tem login. O checkout dava ao navegador um id de cliente convidado no cookie `tucano_guest`, sem assinatura, e um pedido só era encontrado por quem guardava a URL dele. Não havia lista de pedidos: o [UC-ORD-05](../use-cases/UC-ORD-05-view-orders.md) prometia que ela leria o read model `order_views`, e nada alimentava esse read model.

Duas coisas tornavam o isolamento entre clientes só uma aparência. O id do convidado vinha do navegador, então bastava trocar o cookie para virar outra pessoa. E o Kong expõe o commerce inteiro em `/api/commerce`, então uma rota de pedidos por cliente ficaria aberta na borda para quem soubesse um id.

Quero o isolamento de verdade antes de dividir a plataforma em lojas, que é o passo seguinte.

## Decisão

- **Perfis, sem senha.** O navegador guarda até 8 perfis de cliente, e um deles é o ativo. É um laboratório: trocar de perfil faz o papel de entrar com outra conta.
- **Uma sessão assinada.** O cookie `tucano_session` leva os perfis e o ativo, assinado pelo BFF com HMAC-SHA256 sob o `SESSION_SECRET`. Um cookie adulterado vale como nenhum. O perfil guarda só o primeiro nome, tirado do nome digitado no primeiro pedido.
- **O BFF é a fronteira da identidade.** Em todo pedido novo e em toda leitura, ele manda ao commerce o id do perfil ativo como id do cliente. O navegador nunca escolhe esse id.
- **O commerce cobra o isolamento.** As leituras do cliente passam por `/v1/customers/{id}/orders`. Um pedido de outro cliente responde o mesmo 404 de um pedido que não existe, sem dica nenhuma.
- **A borda não expõe as rotas por cliente.** O Kong encerra `/api/commerce/v1/customers` com um 404; só o BFF, na rede interna, chega nelas.
- **A lista é eventual, o pedido é forte.** "Meus pedidos" lê o `order_views` no MongoDB, alimentado pelo `commerce.orders.v2` no consumer group que já estava previsto para ele (`commerce.order-projector`), como o [ADR 0012](0012-acid-writes-base-reads.md) decidiu. O pedido continua lendo o PostgreSQL, agora com o histórico da máquina de estados. A lista avisa que um pedido novo leva alguns segundos para aparecer, e a flag `chaos.commerce.order-projector-paused` pausa o projetor para esse atraso ficar visível.
- **O `order.placed` passa a levar o nome de cada item**, porque a lista mostra o que foi comprado e o evento só tinha o SKU. É uma mudança aditiva, na mesma versão ([ADR 0010](0010-cloudevents-contracts.md)).
- **O pedido conta a própria história.** O BFF junta o histórico do pedido às etapas da remessa na logística, com um prazo curto só para essa busca. Sem a logística, o pedido abre com o que o commerce sabe e um aviso, em vez de esperar o prazo inteiro do BFF.
- **O `tucano_guest` não é adotado.** Ele nunca foi assinado; aproveitar o id dele seria confiar num id que o navegador escolheu. O BFF expira o cookie antigo quando o vê.

## Consequências

- Os pedidos feitos antes desta versão continuam no commerce, mas nenhum perfil os lista.
- O cookie cabe em poucos perfis, por isso o limite de 8.
- Trocar o `SESSION_SECRET` desfaz todas as sessões. A rotação com dois segredos fica para quando fizer falta.
- A lista pode ficar alguns segundos atrás do pedido, e com o Kafka fora ela para no tempo até ele voltar. Com o MongoDB fora, só a lista cai com 503; o pedido, o checkout e o catálogo seguem.
- A rota `GET /v1/orders/{id}`, sem cliente, continua na borda para os laboratórios, com os dados pessoais mascarados como antes. Num sistema de verdade, a borda não exporia o commerce.
- Um login de verdade trocaria só a origem do id: a sessão guardaria o sujeito que o provedor de identidade devolve, e o resto (o commerce por cliente e a borda fechada) continua igual.

## Alternativas consideradas

- **Login de verdade, com OIDC**: mais um serviço pesado para uma loja sem usuários reais. A troca de perfil mostra o isolamento sem senha nenhuma.
- **O cookie sem assinatura de hoje**: qualquer pessoa trocaria o id no navegador e leria os pedidos de outra.
- **Sessão guardada no Redis**: o BFF passaria a depender de um banco, e a queda dele derrubaria a identidade de todo mundo. O cookie assinado não guarda nada no servidor.
- **Um token do BFF para o commerce, com o cliente assinado dentro**: protege melhor quando vários clientes chamam o commerce. Aqui só o BFF chama, na rede interna, e a borda fecha as rotas.
- **Ler a lista no PostgreSQL**: consistente e mais simples, mas contraria o ADR 0012 e apaga o laboratório de consistência que ele prometia, com a mesma informação lida forte de um lado e eventual do outro.
