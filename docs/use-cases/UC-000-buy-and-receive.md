# UC-000 · Comprar e receber um produto

| | |
|---|---|
| **Nível** | resumo |
| **Ator principal** | Cliente |
| **Escopo** | Tucano (todos os contextos) |
| **Gatilho** | o cliente decide comprar |

## Partes interessadas e interesses

- **Cliente**: pagar o preço anunciado uma única vez, receber rápido e saber onde o pacote está.
- **Tucano**: não vender sem estoque, não enviar sem receber e devolver o dinheiro quando não conseguir entregar.
- **Transportadoras e entregadores**: receber remessas com etiqueta e endereço corretos.

## Garantias mínimas

- Dinheiro e estoque sempre batem: pedido cancelado ou devolvido libera o estoque e estorna o pagamento.

## Garantias de sucesso

- O produto chega ao cliente e o pedido termina em `delivered`.

## Cenário principal de sucesso

1. O cliente consulta o catálogo (UC-CAT-01).
2. O cliente faz o pedido (UC-ORD-01).
3. O cliente paga (UC-PAY-01) e o PSP confirma (UC-PAY-02).
4. A logística cria a remessa (UC-SHP-01), escolhe a transportadora (UC-SHP-02) e gera a etiqueta (UC-SHP-03).
5. A remessa é coletada (UC-SHP-04) e, se for de transportadora parceira, passa por hubs (UC-SHP-05).
6. A remessa sai para entrega (UC-SHP-06) e o cliente acompanha ao vivo (UC-TRK-03).
7. O entregador confirma a entrega (UC-SHP-07) e o pedido é concluído (UC-ORD-04).
8. O cliente é notificado nos marcos principais (UC-NTF-01).

## Extensões

- 3a. Pagamento recusado ou não feito no prazo: o pedido é cancelado e o estoque liberado (UC-ORD-03).
- 7a. Destinatário ausente: nova tentativa; depois da terceira, devolução ao remetente (UC-SHP-08) e estorno (UC-PAY-04).
- *a. O cliente cancela antes da coleta (UC-ORD-02): a remessa é cancelada (UC-SHP-09) e o pagamento estornado.
