import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import type { Session } from '../src/session/index.ts';
import { bffOver } from './support/bff.ts';
import { PAY_KEY } from './support/example-screens.ts';
import { example } from './support/examples.ts';
import { type FakeServices, fakeServices, ids, serveStores } from './support/fake-services.ts';
import { sessionCookie, sessionCookieSet, sessionSet } from './support/sessions.ts';
import {
  ana,
  anaShopping,
  bruno,
  customerOrderJson,
  histories,
  pendingOrder,
  visitor,
} from './support/upstream-data.ts';

const ANA_ID = '0199a2b4-6a01-7b02-8c03-d4e5f6a7b801';
const BRUNO_ID = '0199a2b4-6a01-7b02-8c03-d4e5f6a7b802';
/** Ana's order, as Commerce keeps it: only her id opens it. */
const ORDER = pendingOrder.orderId;

/** The same browser, now shopping as Bruno. */
const brunoShopping: Session = { ...anaShopping, active: bruno.id };

describe('two profiles in one browser', () => {
  let services: FakeServices;

  before(async () => {
    services = await fakeServices((app) => serveStores(app));
  });

  after(() => services.close());

  it('creates a profile and lands on the orders of the store it came from', async () => {
    const response = await bffOver(services, { newId: ids(ANA_ID) }).inject({
      method: 'POST',
      url: '/v1/profiles?store=arara',
      payload: { name: '  Ana  ' },
    });

    assert.equal(response.statusCode, 303);
    assert.equal(response.headers.location, '/bff/v1/stores/arara/orders');
    assert.equal(response.headers['cache-control'], 'no-store');
    assert.equal(response.body, '');
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), {
      active: ANA_ID,
      profiles: [{ id: ANA_ID, name: 'Ana' }],
    });
  });

  it('lands on the start of the platform when it came from no store, or from one it does not have', async () => {
    for (const url of ['/v1/profiles', '/v1/profiles?store=nope', '/v1/profiles?store=No%20Way']) {
      const response = await bffOver(services, { newId: ids(ANA_ID) }).inject({
        method: 'POST',
        url,
        payload: { name: 'Ana' },
      });

      assert.equal(response.statusCode, 303, url);
      assert.equal(response.headers.location, '/bff/v1', url);
    }
  });

  it('creates a second profile, which shops from then on, and switches back', async () => {
    const bff = bffOver(services, { newId: ids(ANA_ID, BRUNO_ID) });
    const first = await bff.inject({
      method: 'POST',
      url: '/v1/profiles',
      payload: { name: 'Ana' },
    });
    const second = await bff.inject({
      method: 'POST',
      url: '/v1/profiles',
      headers: { cookie: String(sessionCookieSet(first.headers['set-cookie'])) },
      payload: { name: 'Bruno' },
    });
    const back = await bff.inject({
      method: 'POST',
      url: '/v1/profiles/active?store=sabia',
      headers: { cookie: String(sessionCookieSet(second.headers['set-cookie'])) },
      payload: { profileId: ANA_ID },
    });

    const both = [
      { id: ANA_ID, name: 'Ana' },
      { id: BRUNO_ID, name: 'Bruno' },
    ];
    assert.deepStrictEqual(sessionSet(second.headers['set-cookie']), {
      active: BRUNO_ID,
      profiles: both,
    });
    assert.equal(back.statusCode, 303);
    assert.equal(back.headers.location, '/bff/v1/stores/sabia/orders');
    assert.deepStrictEqual(sessionSet(back.headers['set-cookie']), {
      active: ANA_ID,
      profiles: both,
    });
  });

  it('lists the profiles of the browser, each but the one shopping with its switch', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/profiles?store=arara',
      headers: { cookie: sessionCookie(anaShopping) },
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('profiles.json'));
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('refuses a ninth profile, next to the name', async () => {
    const eight: Session = {
      active: ana.id,
      profiles: Array.from({ length: 8 }, (_, index) => ({
        id: `0199a2b4-6a01-7b02-8c03-d4e5f6a7b9${String(index).padStart(2, '0')}`,
        name: `Perfil ${index + 1}`,
      })).map((profile, index) => (index === 0 ? { ...profile, id: ana.id } : profile)),
    };
    const response = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/profiles',
      headers: { cookie: sessionCookie(eight) },
      payload: { name: 'Nona' },
    });

    assert.equal(response.statusCode, 422);
    assert.equal(response.json().detail, 'Não deu para criar mais um perfil.');
    assert.deepStrictEqual(response.json().errors, {
      name: ['Este navegador já guarda 8 perfis, o máximo. Compre como um deles.'],
    });
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('refuses to shop as a profile the browser does not hold, whatever id the form brings', async () => {
    const stranger = '0199a2b4-6a01-7b02-8c03-d4e5f6a7b8ff';
    for (const cookie of [sessionCookie(anaShopping), undefined]) {
      const response = await bffOver(services).inject({
        method: 'POST',
        url: '/v1/profiles/active',
        ...(cookie === undefined ? {} : { headers: { cookie } }),
        payload: { profileId: stranger },
      });

      assert.equal(response.statusCode, 422);
      assert.deepStrictEqual(response.json().errors, {
        profileId: ['Escolha um dos perfis da lista.'],
      });
      assert.equal(response.headers['set-cookie'], undefined);
    }
  });

  it('asks for a name a person can read, of 40 characters at most', async () => {
    const refused = async (name: unknown) => {
      const response = await bffOver(services).inject({
        method: 'POST',
        url: '/v1/profiles',
        payload: { name },
      });
      assert.equal(response.statusCode, 422);
      return response.json().errors.name;
    };

    assert.deepStrictEqual(await refused('   '), ['Escreva o nome de quem está comprando.']);
    assert.deepStrictEqual(await refused('\u0000\u0007'), [
      'Escreva o nome de quem está comprando.',
    ]);
    assert.deepStrictEqual(await refused('A'.repeat(41)), ['Use até 40 caracteres.']);
  });
});

describe('the isolation between profiles', () => {
  let services: FakeServices;
  let payments = 0;

  before(async () => {
    services = await fakeServices((app) => {
      serveStores(app);
      // Commerce answers an order only to its customer, and the same 404 to anybody else.
      app.get<{ Params: { customerId: string; orderId: string } }>(
        '/v1/stores/arara/customers/:customerId/orders/:orderId',
        async (request, reply) =>
          request.params.customerId === ana.id && request.params.orderId === ORDER
            ? customerOrderJson(pendingOrder, histories.pending)
            : reply.code(404).send({ status: 404 }),
      );
      app.post('/v1/orders/:orderId/payments', async (_request, reply) => {
        payments += 1;
        return reply.code(202).send({ paymentId: 'p1', orderId: ORDER, status: 'pending' });
      });
    });
  });

  after(() => services.close());

  it('opens the order for the profile that placed it', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `/v1/stores/arara/orders/${ORDER}`,
      headers: { cookie: sessionCookie(anaShopping) },
    });

    assert.equal(response.statusCode, 200);
  });

  it('answers 404 to another profile of the same browser, asking Commerce as that profile', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `/v1/stores/arara/orders/${ORDER}`,
      headers: { cookie: sessionCookie(brunoShopping) },
    });

    assert.equal(response.statusCode, 404);
    assert.equal(
      services.received.at(-1)?.url,
      `/v1/stores/arara/customers/${bruno.id}/orders/${ORDER}`,
    );
  });

  it('never sends the payment of an order of another profile', async () => {
    const paymentsSoFar = payments;
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `/v1/stores/arara/orders/${ORDER}/payments`,
      headers: { cookie: sessionCookie(brunoShopping) },
      payload: { idempotencyKey: PAY_KEY, cardToken: 'tok_visa' },
    });

    assert.equal(response.statusCode, 404);
    assert.equal(payments, paymentsSoFar);
  });

  it('answers 404 to a browser with no session, without asking Commerce', async () => {
    const commerceCalls = () =>
      services.received.filter(
        ({ url }) => url.includes('/customers/') || url.endsWith('/payments'),
      ).length;
    const received = commerceCalls();
    for (const request of [
      { method: 'GET' as const, url: `/v1/stores/arara/orders/${ORDER}` },
      {
        method: 'POST' as const,
        url: `/v1/stores/arara/orders/${ORDER}/payments`,
        payload: { idempotencyKey: PAY_KEY, cardToken: 'tok_visa' },
      },
    ]) {
      const response = await bffOver(services).inject(request);

      assert.equal(response.statusCode, 404, request.method);
    }
    assert.equal(commerceCalls(), received);
  });

  it('keeps each profile to its own orders, with a visitor of the same browser too', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `/v1/stores/arara/orders/${ORDER}`,
      headers: { cookie: sessionCookie({ ...anaShopping, active: visitor.id }) },
    });

    assert.equal(response.statusCode, 404);
  });
});
