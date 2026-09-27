# 0019. Desistir de um pagamento sem fechar a porta para o PSP

- Status: aceito
- Data: 2026-09-27

## Contexto

Um pagamento fica `pending` quando a resposta da cobrança não volta. A conciliação (UC-PAY-03) pergunta ao PSP e, na maior parte das vezes, encontra a cobrança e aplica o resultado. Às vezes o PSP não tem cobrança nenhuma, porque o request se perdeu antes de chegar. Enquanto o pedido espera, o cliente ainda pode repetir o pagamento. Quando a reserva vence, o pagamento precisa de um fim.

O problema é que desistir é um palpite. Uma repetição do cliente pode estar a caminho do PSP no instante da desistência, e a busca de um PSP de verdade pode estar atrasada em relação às cobranças. A cobrança pode aparecer depois, e o dinheiro não pode ficar sem destino.

## Decisão

- Um estado próprio, `abandoned`: a conciliação desiste só quando o PSP não tem cobrança e o pedido já não espera.
- `abandoned` aceita a palavra tardia do PSP. Captura vira `refund_requested` e o dinheiro volta pelo estorno (UC-PAY-04); recusa vira `failed`.
- A conciliação continua olhando os pagamentos `abandoned` que ganharam um id de cobrança, para o caso de o webhook dessa cobrança também se perder.

## Consequências

- Nenhuma cobrança fica sem destino, qualquer que seja a ordem em que as coisas acontecem.
- A máquina de estados ganha um estado e duas transições, e o índice parcial da conciliação inclui `abandoned` com cobrança.
- `abandoned` libera o índice de um pagamento pendente por pedido, tira o pagamento da varredura e impede o reenvio da cobrança, três efeitos que um motivo de falha em texto não daria.

## Alternativas consideradas

- **`failed` com motivo `not_charged`**: o estado mentiria (nada falhou no PSP), e aceitar a captura tardia exigiria uma transição de `failed` para `captured`, que vale para toda recusa.
- **Impedir a corrida com lock ou versão entre a desistência e a repetição do cliente**: fecha uma das janelas, mas não a busca atrasada do PSP.
- **Nunca desistir**: o pagamento sem cobrança ficaria pendente para sempre, travando o índice de um pagamento pendente por pedido e a varredura.
