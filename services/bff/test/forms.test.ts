import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  FormAlreadyUsed,
  newOrderOf,
  OrderNotPlaced,
  ProductOutOfLine,
  readOrderForm,
  refusalError,
} from '../src/checkout/index.ts';
import { href, InvalidForm, money, path } from '../src/hypermedia/index.ts';
import { canonicalCode } from '../src/tracking/code.ts';

const filled = {
  idempotencyKey: '0199a2b4-8a10-7c51-b0d2-3e4f5a6b7c8d',
  sku: 'BOOK-DDD-001',
  quantity: 1,
  name: '  Ana Souza ',
  email: 'ana@example.com',
  postalCode: '30160-011',
  thoroughfareType: 'Rua',
  thoroughfareName: 'da Bahia',
  number: '1200',
  complement: '',
  neighborhood: 'Centro',
  municipality: 'Belo Horizonte',
  state: 'MG',
};

describe('the order form', () => {
  it('reads a filled form, trimming what people type', () => {
    const form = readOrderForm(filled);

    assert.equal(form.name, 'Ana Souza');
    assert.equal(form.complement, null);
    assert.equal(form.state, 'MG');
  });

  it('gives every message at once, each next to its field', () => {
    const error = catchError(() =>
      readOrderForm({ ...filled, email: 'ana@', postalCode: '3016', state: 'XX', number: ' ' }),
    );

    assert.ok(error instanceof InvalidForm);
    assert.equal(error.message, 'Alguns campos precisam de atenção.');
    assert.deepStrictEqual(error.fieldErrors, {
      email: ['Informe um e-mail válido.'],
      postalCode: ['O CEP tem 8 números.'],
      number: ['Informe o número, ou S/N quando não houver.'],
      state: ['Escolha o estado.'],
    });
  });

  it('says how long a field may be', () => {
    const error = catchError(() => readOrderForm({ ...filled, complement: 'x'.repeat(81) }));

    assert.ok(error instanceof InvalidForm);
    assert.deepStrictEqual(error.fieldErrors, { complement: ['Use até 80 caracteres.'] });
  });

  it('takes the quantity as a number or as its digits', () => {
    assert.equal(readOrderForm({ ...filled, quantity: '3' }).quantity, 3);
  });

  it('sends the address to Commerce in pieces, from the state down', () => {
    const order = newOrderOf(readOrderForm(filled), '0199a2b4-1111-7222-8333-444455556666');

    assert.deepStrictEqual(order, {
      customer: {
        id: '0199a2b4-1111-7222-8333-444455556666',
        name: 'Ana Souza',
        email: 'ana@example.com',
      },
      shippingAddress: {
        thoroughfare: { type: 'Rua', name: 'da Bahia' },
        number: '1200',
        complement: null,
        divisions: [
          { kind: 'state', code: 'MG', name: 'Minas Gerais' },
          { kind: 'municipality', code: null, name: 'Belo Horizonte' },
          { kind: 'neighborhood', code: null, name: 'Centro' },
        ],
        postalCode: '30160-011',
      },
      items: [{ sku: 'BOOK-DDD-001', quantity: 1 }],
    });
  });

  it('leaves the neighborhood out when nobody filled it', () => {
    const order = newOrderOf(readOrderForm({ ...filled, neighborhood: '' }), 'guest');

    assert.deepStrictEqual(
      order.shippingAddress.divisions.map((division) => division.kind),
      ['state', 'municipality'],
    );
  });

  it('puts the fields Commerce refused next to the fields of the form', () => {
    const error = refusalError({
      outcome: 'refused',
      status: 422,
      problem: null,
      fields: ['customer.email', 'shippingAddress.divisions.1.name', 'items.0.sku'],
    });

    assert.ok(error instanceof InvalidForm);
    assert.deepStrictEqual(error.fieldErrors, {
      email: ['Confira este campo.'],
      municipality: ['Confira este campo.'],
    });
  });

  it('tells apart the conflicts Commerce names, which share a status', () => {
    const refused = (status: 409 | 422, problem: string) =>
      refusalError({ outcome: 'refused', status, problem, fields: [] });

    assert.ok(refused(409, 'product-unavailable') instanceof ProductOutOfLine);
    assert.ok(refused(409, 'stock-not-reserved') instanceof OrderNotPlaced);
    assert.ok(refused(422, 'idempotency-key-reused') instanceof FormAlreadyUsed);
  });

  it('reads an unnamed 409 as a stock that ran out', () => {
    const error = refusalError({ outcome: 'refused', status: 409, problem: null, fields: [] });

    assert.ok(error instanceof OrderNotPlaced);
    assert.equal(error.category, 'conflict');
  });
});

describe('the tracking code', () => {
  it('reads codes the way people type them', () => {
    assert.equal(canonicalCode('  tx02px83txc5g00 '), 'TX02PX83TXC5G00');
  });

  it('reads the letters Crockford Base32 takes as digits', () => {
    assert.equal(canonicalCode('TXO2PX83TXC5GOI'), 'TX02PX83TXC5G01');
    assert.equal(canonicalCode('TX02PX83TXC5GLl'), 'TX02PX83TXC5G11');
  });
});

describe('the hypermedia vocabulary', () => {
  it('formats money for people and keeps the cents for machines', () => {
    assert.deepStrictEqual(money(15990, 'BRL'), {
      amount: 15990,
      currency: 'BRL',
      formatted: 'R$ 159,90',
    });
  });

  it('knows currencies without cents', () => {
    assert.match(money(1500, 'JPY').formatted, /1\.500$/);
  });

  it('encodes each value as one segment of the path', () => {
    assert.equal(href(path`/products/${'A/B C'}`), '/bff/v1/products/A%2FB%20C');
  });

  it('leaves out the query values it was not given', () => {
    assert.equal(href('/orders/1', { awaiting: undefined }), '/bff/v1/orders/1');
    assert.equal(href('/checkout', { sku: 'X', quantity: 2 }), '/bff/v1/checkout?sku=X&quantity=2');
  });
});

function catchError(run: () => unknown): unknown {
  try {
    run();
  } catch (error) {
    return error;
  }
  assert.fail('expected an error');
}
