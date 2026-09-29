import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { buildApp } from '../src/app.ts';
import { rel } from '../src/hypermedia/index.ts';
import { bffOver } from './support/bff.ts';
import { testConfig } from './support/config.ts';
import { FORM_KEY, PAY_KEY } from './support/example-screens.ts';
import { example } from './support/examples.ts';
import { type FakeServices, fakeServices, ids } from './support/fake-services.ts';
import { sessionCookie, sessionSet } from './support/sessions.ts';
import {
  ana,
  anaShopping,
  customerOrderJson,
  dddBook,
  deliveredParcel,
  histories,
  orderJson,
  pendingOrder,
  productJson,
  trackingJson,
  visitor,
  visitorShopping,
} from './support/upstream-data.ts';

const ORDER = pendingOrder.orderId;
/** The key the fake PSP answers 503 to, as if its circuit breaker were open. */
const KEY_WHILE_PSP_IS_OUT = '0199a2b4-9b21-7d62-a1e3-000000000503';
const SIREN = /^application\/vnd\.siren\+json/;
const ANA = sessionCookie(anaShopping);

/** The customer id of the last order the fake Commerce received. */
function customerSentTo(services: FakeServices): string | undefined {
  const body = services.received.at(-1)?.body as { customer?: { id?: string } } | undefined;
  return body?.customer?.id;
}

/** Picks the entities of a relation out of a screen as it came over the wire. */
function isA(relation: string): (entity: { rel: string[] }) => boolean {
  return (entity) => entity.rel.includes(relation);
}

const orderForm = {
  idempotencyKey: FORM_KEY,
  sku: 'BOOK-DDD-001',
  quantity: 1,
  name: 'Ana Souza',
  email: 'ana@example.com',
  postalCode: '30160-011',
  thoroughfareType: 'Rua',
  thoroughfareName: 'da Bahia',
  number: '1200',
  complement: 'apto 42',
  neighborhood: 'Centro',
  municipality: 'Belo Horizonte',
  state: 'MG',
};

describe('shopping', () => {
  let services: FakeServices;

  before(async () => {
    services = await fakeServices((app) => {
      app.get('/v1/products', async () => ({
        data: [productJson(dddBook)],
        page: 1,
        perPage: 20,
        total: 1,
      }));
      app.get<{ Params: { sku: string } }>('/v1/products/:sku', async (request, reply) => {
        if (request.params.sku === 'BOOK-DDD-001') {
          return productJson(dddBook);
        }
        if (request.params.sku === 'BOOK-OLD-001') {
          return productJson({ ...dddBook, sku: 'BOOK-OLD-001', status: 'discontinued' });
        }
        return reply.code(404).type('application/problem+json').send({ status: 404 });
      });
      app.post('/v1/orders', async (request, reply) => {
        const email = (request.body as { customer: { email: string } }).customer.email;
        if (email === 'stock@example.com') {
          return reply.code(409).type('application/problem+json').send({ status: 409 });
        }
        if (email === 'discontinued@example.com') {
          return reply.code(409).type('application/problem+json').send({
            type: 'https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/problems.md#product-unavailable',
            status: 409,
          });
        }
        if (email === 'refused@example.com') {
          return reply
            .code(422)
            .type('application/problem+json')
            .send({ status: 422, errors: { 'customer.email': ['not an e-mail address'] } });
        }
        return reply.code(201).send(orderJson(pendingOrder));
      });
    });
  });

  after(() => services.close());

  it('opens the entry point without asking any service', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({ method: 'GET', url: '/v1' });

    assert.equal(response.statusCode, 200);
    assert.match(String(response.headers['content-type']), SIREN);
    assert.equal(response.headers['cache-control'], 'no-store');
    assert.deepStrictEqual(response.json(), example('home.json'));
    assert.equal(services.received.length, received);
  });

  it('takes the correlation id along to the catalog', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/products',
      headers: { 'x-correlation-id': 'req-9#1' },
    });

    assert.equal(response.statusCode, 200);
    assert.equal(response.json().entities.filter(isA(rel.item)).length, 1);
    assert.equal(services.received.at(-1)?.headers['x-correlation-id'], 'req-9#1');
  });

  it('names the shopper in the navigation of every screen', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/products',
      headers: { cookie: ANA },
    });
    const navigation = response.json().entities.find(isA(rel.navigation));

    assert.deepStrictEqual(navigation.properties.shopper, {
      profileId: ana.id,
      label: 'Ana',
      initial: 'A',
    });
  });

  it('answers a product the catalog does not know with a 404 in Portuguese', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/products/NOPE-001',
    });

    assert.equal(response.statusCode, 404);
    assert.equal(response.json().detail, 'Não encontrei o produto NOPE-001.');
  });

  it('never asks the catalog for a SKU out of shape', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/products/no%20way',
    });

    assert.equal(response.statusCode, 404);
    assert.equal(services.received.length, received);
  });

  it('opens the checkout and starts the session of a visitor', async () => {
    const response = await bffOver(services, { newId: ids(visitor.id, FORM_KEY) }).inject({
      method: 'GET',
      url: '/v1/checkout?sku=BOOK-DDD-001&quantity=1',
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('checkout.json'));
    assert.match(
      String(response.headers['set-cookie']),
      /^tucano_session=[\w-]+\.[\w-]+; Path=\/bff; Max-Age=31536000; HttpOnly; SameSite=Lax; Secure$/,
    );
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), visitorShopping);
  });

  it('keeps the session the browser already has', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/checkout?sku=BOOK-DDD-001&quantity=2',
      headers: { cookie: `theme=dark; ${ANA}` },
    });

    assert.equal(response.statusCode, 200);
    // Intl keeps a no-break space after the symbol, so R$ never ends a line alone.
    assert.equal(response.json().properties.subtotal.formatted, 'R$ 319,80');
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('refuses more units than an order takes, next to the quantity', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/checkout?sku=BOOK-DDD-001&quantity=11',
    });

    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(response.json().errors, { quantity: ['Escolha de 1 a 10 unidades.'] });
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('does not check out a product out of line', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/checkout?sku=BOOK-OLD-001&quantity=1',
    });

    assert.equal(response.statusCode, 409);
    assert.equal(
      response.json().detail,
      'Domain-Driven Design saiu de linha e não está mais à venda.',
    );
  });

  it('places the order as the profile shopping, with the key of the form', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/orders',
      headers: { cookie: ANA, 'x-correlation-id': 'req-9#2' },
      payload: orderForm,
    });

    assert.equal(response.statusCode, 201);
    assert.equal(response.headers.location, `/bff/v1/orders/${ORDER}`);
    assert.deepStrictEqual(response.json(), example('order-pending-payment.json'));
    assert.equal(response.headers['set-cookie'], undefined);
    const placed = services.received.at(-1);
    assert.equal(placed?.headers['idempotency-key'], FORM_KEY);
    assert.equal(placed?.headers['x-correlation-id'], 'req-9#2');
    assert.partialDeepStrictEqual(placed?.body, {
      customer: { id: ana.id, email: 'ana@example.com' },
      shippingAddress: {
        thoroughfare: { type: 'Rua', name: 'da Bahia' },
        divisions: [{ kind: 'state', code: 'MG', name: 'Minas Gerais' }],
      },
      items: [{ sku: 'BOOK-DDD-001', quantity: 1 }],
    });
  });

  it('names the profile a checkout started after its first order, with the first name only', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/orders',
      headers: { cookie: sessionCookie(visitorShopping) },
      payload: { ...orderForm, name: '  Maria   da Silva ' },
    });

    assert.equal(response.statusCode, 201);
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), {
      active: visitor.id,
      profiles: [{ id: visitor.id, name: 'Maria' }],
    });
    assert.equal(customerSentTo(services), visitor.id);
  });

  it('starts a session for an order that comes without one', async () => {
    const newcomer = '0199a2b4-7d1e-7f20-8a31-b4c5d6e7f809';
    const response = await bffOver(services, { newId: ids(newcomer, PAY_KEY) }).inject({
      method: 'POST',
      url: '/v1/orders',
      payload: orderForm,
    });

    assert.equal(response.statusCode, 201);
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), {
      active: newcomer,
      profiles: [{ id: newcomer, name: 'Ana' }],
    });
    assert.equal(customerSentTo(services), newcomer);
  });

  it('gives back every mistake of the form at once, without bothering Commerce', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/orders',
      payload: { ...orderForm, email: 'ana@', postalCode: '3016' },
    });

    assert.equal(response.statusCode, 422);
    const { correlationId: _id, instance, ...problem } = response.json();
    const {
      correlationId: _exampleId,
      instance: _exampleInstance,
      ...expected
    } = example('problems/validation.json') as Record<string, unknown>;
    assert.equal(instance, '/v1/orders');
    assert.deepStrictEqual(problem, expected);
    assert.equal(services.received.length, received);
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('says the stock ran out when Commerce cannot reserve it', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/orders',
      headers: { cookie: ANA },
      payload: { ...orderForm, email: 'stock@example.com' },
    });

    assert.equal(response.statusCode, 409);
    assert.match(response.json().detail, /estoque/);
  });

  it('keeps the session it started even when Commerce refuses the order', async () => {
    const newcomer = '0199a2b4-7d1e-7f20-8a31-b4c5d6e7f80a';
    const response = await bffOver(services, { newId: ids(newcomer) }).inject({
      method: 'POST',
      url: '/v1/orders',
      payload: { ...orderForm, email: 'stock@example.com' },
    });

    // A retry of the same form goes as the same customer, so the same key meets the same body.
    assert.equal(response.statusCode, 409);
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), {
      active: newcomer,
      profiles: [{ id: newcomer, name: null }],
    });
  });

  it('tells a product out of line from a stock that ran out, by the type of the problem', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/orders',
      headers: { cookie: ANA },
      payload: { ...orderForm, email: 'discontinued@example.com' },
    });

    assert.equal(response.statusCode, 409);
    assert.equal(response.json().detail, 'Esse produto saiu de linha e não está mais à venda.');
  });

  it('puts what Commerce refused next to the field of the form', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/orders',
      headers: { cookie: ANA },
      payload: { ...orderForm, email: 'refused@example.com' },
    });

    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(response.json().errors, { email: ['Confira este campo.'] });
  });
});

describe('paying', () => {
  let services: FakeServices;

  before(async () => {
    services = await fakeServices((app) => {
      app.get<{ Params: { customerId: string; orderId: string } }>(
        '/v1/customers/:customerId/orders/:orderId',
        async (request, reply) =>
          request.params.customerId === ana.id && request.params.orderId === ORDER
            ? customerOrderJson(pendingOrder, histories.pending)
            : reply.code(404).send({ status: 404 }),
      );
      app.post('/v1/orders/:orderId/payments', async (request, reply) => {
        if (request.headers['idempotency-key'] === KEY_WHILE_PSP_IS_OUT) {
          return reply.code(503).header('retry-after', '17').send({ status: 503 });
        }
        return reply.code(202).send({ paymentId: 'p1', orderId: ORDER, status: 'pending' });
      });
    });
  });

  after(() => services.close());

  it('shows the order of the shopper with its pay form', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `/v1/orders/${ORDER}`,
      headers: { cookie: ANA },
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('order-pending-payment.json'));
    assert.equal(services.received.at(-1)?.url, `/v1/customers/${ana.id}/orders/${ORDER}`);
  });

  it('answers an order that does not exist with a 404', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/orders/0199a2b4-0000-7000-8000-000000000000',
      headers: { cookie: ANA },
    });

    assert.equal(response.statusCode, 404);
    assert.equal(
      response.json().detail,
      'Não encontrei esse pedido. Se ele foi feito com outro perfil, troque de perfil e abra de novo.',
    );
  });

  it('follows the payment until the PSP answers', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `/v1/orders/${ORDER}/payments`,
      headers: { cookie: ANA },
      payload: { idempotencyKey: PAY_KEY, cardToken: 'tok_visa' },
    });

    assert.equal(response.statusCode, 202);
    assert.equal(response.headers.location, `/bff/v1/orders/${ORDER}?awaiting=payment`);
    assert.deepStrictEqual(response.json(), example('order-awaiting-payment.json'));
    assert.equal(services.received.at(-2)?.headers['idempotency-key'], PAY_KEY);
  });

  it('says when to try again while payments are out', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `/v1/orders/${ORDER}/payments`,
      headers: { cookie: ANA },
      payload: { idempotencyKey: KEY_WHILE_PSP_IS_OUT, cardToken: 'tok_visa' },
    });

    assert.equal(response.statusCode, 503);
    assert.equal(response.headers['retry-after'], '17');
    assert.equal(
      response.json().detail,
      'O pagamento está fora do ar agora. Tente de novo em 17 segundos.',
    );
  });

  it('refuses a card that is not one of the test cards', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `/v1/orders/${ORDER}/payments`,
      headers: { cookie: ANA },
      payload: { idempotencyKey: PAY_KEY, cardToken: '4111111111111111' },
    });

    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(response.json().errors, {
      cardToken: ['Escolha um dos cartões de teste.'],
    });
    assert.equal(services.received.length, received);
  });
});

describe('tracking', () => {
  let services: FakeServices;

  before(async () => {
    services = await fakeServices((app) => {
      app.get('/v1/tracking/:code', async (request, reply) =>
        (request.params as { code: string }).code === deliveredParcel.trackingCode
          ? trackingJson(deliveredParcel)
          : reply.code(404).send({ status: 404 }),
      );
    });
  });

  after(() => services.close());

  it('sends a typed code to the page of the parcel', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/tracking?code=%20tx02px83txc5goo%20',
    });

    assert.equal(response.statusCode, 303);
    assert.equal(response.headers.location, '/bff/v1/tracking/TX02PX83TXC5G00');
  });

  it('refuses a code out of shape next to the field', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/tracking?code=abc',
    });

    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(Object.keys(response.json().errors), ['code']);
  });

  it('shows the journey of the parcel', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/tracking/TX02PX83TXC5G00',
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('tracking.json'));
  });

  it('explains a code with no news yet', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/tracking/TX02PX83TXC5G99',
    });

    assert.equal(response.statusCode, 404);
    assert.match(response.json().detail, /^Ainda não há notícias da entrega TX02PX83TXC5G99/);
  });
});

describe('when a service fails', () => {
  it('answers 503 with Retry-After when the service does not answer in time', async () => {
    const services = await fakeServices((app) => {
      app.get('/v1/products', async () => {
        await new Promise((resolve) => setTimeout(resolve, 500));
        return { data: [], page: 1, perPage: 20, total: 0 };
      });
    });
    try {
      const response = await bffOver(services, { env: { UPSTREAM_TIMEOUT_MS: '50' } }).inject({
        method: 'GET',
        url: '/v1/products',
      });

      assert.equal(response.statusCode, 503);
      assert.equal(response.headers['retry-after'], '5');
      assert.equal(
        response.json().detail,
        'Agora não consegui falar com o catálogo. Tente de novo em 5 segundos.',
      );
    } finally {
      await services.close();
    }
  });

  it('answers 503 when nothing listens where the service should be', async () => {
    const config = testConfig({ LOGISTICS_URL: 'http://127.0.0.1:9' });
    const response = await buildApp({ config }).inject({
      method: 'GET',
      url: '/v1/tracking/TX02PX83TXC5G00',
    });

    assert.equal(response.statusCode, 503);
    assert.equal(response.headers['retry-after'], '5');
  });

  it('hides a broken contract behind a 500, for a person to look at the logs', async () => {
    const services = await fakeServices((app) => {
      app.get('/v1/products', async () => ({ data: 'not a list' }));
    });
    try {
      const response = await bffOver(services).inject({ method: 'GET', url: '/v1/products' });

      assert.equal(response.statusCode, 500);
      assert.doesNotMatch(response.body, /not a list/);
    } finally {
      await services.close();
    }
  });

  it('stays ready while the services behind are out', async () => {
    const config = testConfig({
      CATALOG_URL: 'http://127.0.0.1:9',
      COMMERCE_URL: 'http://127.0.0.1:9',
      LOGISTICS_URL: 'http://127.0.0.1:9',
    });
    const response = await buildApp({ config }).inject({ method: 'GET', url: '/health/ready' });

    assert.equal(response.statusCode, 200);
  });
});
