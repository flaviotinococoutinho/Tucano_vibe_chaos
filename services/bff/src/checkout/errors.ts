import { DomainError } from '../platform/domain-error.ts';

export class ProductOutOfLine extends DomainError {
  readonly category = 'conflict';

  constructor(name: string) {
    super(`${name} saiu de linha e não está mais à venda.`);
  }
}

export class OrderNotPlaced extends DomainError {
  readonly category = 'conflict';

  constructor() {
    super(
      'Não deu para reservar esse produto agora: pode ser que o estoque tenha acabado. Tente outra quantidade ou outro produto.',
    );
  }
}

/** The same form went again with other data after it had placed an order. */
export class FormAlreadyUsed extends DomainError {
  readonly category = 'invalid_input';

  constructor() {
    super('Este formulário já fechou um pedido. Volte ao produto e comece de novo.');
  }
}
