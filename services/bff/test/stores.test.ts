import assert from 'node:assert/strict';
import { after, before, beforeEach, describe, it } from 'node:test';
import type { FastifyBaseLogger } from 'fastify';
import { rel } from '../src/hypermedia/index.ts';
import { storesFrom } from '../src/stores/index.ts';
import {
  type Catalog,
  ServiceUnavailable,
  type Store,
  type Trace,
  UpstreamContractBroken,
} from '../src/upstream/index.ts';
import { bffOver } from './support/bff.ts';
import { PAY_KEY } from './support/example-screens.ts';
import { type FakeServices, fakeServices, ids, serveStores } from './support/fake-services.ts';
import { orderForm } from './support/forms.ts';
import { sessionCookie } from './support/sessions.ts';
import {
  ana,
  anaShopping,
  arara,
  bemtevi,
  booksDelivered,
  customerOrderJson,
  deliveredParcel,
  everyStore,
  histories,
  mugsInSabia,
  pendingOrder,
  sabia,
  summaryJson,
  trackingJson,
} from './support/upstream-data.ts';

const ANA = sessionCookie(anaShopping);
const MINUTE_MS = 60_000;

/** Lets every promise already on its way settle, like a call the BFF made behind a screen. */
function settled(): Promise<void> {
  return new Promise((resolve) => setImmediate(resolve));
}

/** A trace whose error lines a test can read. */
function traceFor(errors: string[] = []): Trace {
  const log = {
    error: (_details: unknown, message: string) => errors.push(message),
    warn: () => {},
    info: () => {},
    debug: () => {},
  };
  return { correlationId: 'req-1', log: log as unknown as FastifyBaseLogger };
}

type Mood = 'answers' | 'out' | 'broken';

/** The catalog of the stores, as the upstream module reads it, with every call it gets. */
function catalogOf(stores: readonly Store[]) {
  const asked: string[] = [];
  const state = { mood: 'answers' as Mood, stores: [...stores], gate: Promise.resolve() };
  const answer = async <T>(what: string, value: () => T): Promise<T> => {
    asked.push(what);
    await state.gate;
    if (state.mood === 'out') {
      throw new ServiceUnavailable('Agora não consegui falar com o catálogo.', 5);
    }
    if (state.mood === 'broken') {
      throw new UpstreamContractBroken('catalog GET /v1/stores', 'a body that is not JSON');
    }
    return value();
  };
  const catalog: Catalog = {
    stores: () => answer('list', () => state.stores),
    store: (slug) => answer(slug, () => state.stores.find((store) => store.slug === slug) ?? null),
    page: () => Promise.reject(new Error('the stores never ask for products')),
    product: () => Promise.reject(new Error('the stores never ask for products')),
  };
  return { catalog, asked, state };
}

describe('the stores kept in memory', () => {
  let now = 0;
  const clock = () => now;

  beforeEach(() => {
    now = 0;
  });

  it('asks the catalog for a store once, and answers from memory for a minute', async () => {
    const { catalog, asked } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });

    assert.deepStrictEqual(await stores.find('arara', traceFor()), arara);
    now += MINUTE_MS - 1;
    assert.deepStrictEqual(await stores.find('arara', traceFor()), arara);
    assert.deepStrictEqual(asked, ['arara']);
  });

  it('asks again after a minute, answering with what it kept while the catalog answers behind it', async () => {
    const { catalog, asked, state } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });
    await stores.find('arara', traceFor());
    const renamed = { ...arara, tagline: 'Livros para quem cuida de sistemas.' };
    state.stores = [renamed, bemtevi, sabia];

    now += MINUTE_MS;
    assert.deepStrictEqual(await stores.find('arara', traceFor()), arara);
    await settled();
    assert.deepStrictEqual(await stores.find('arara', traceFor()), renamed);
    assert.deepStrictEqual(asked, ['arara', 'arara']);
  });

  it('keeps answering with a store it knows while the catalog is out, however long it takes', async () => {
    const { catalog, state } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });
    await stores.find('sabia', traceFor());
    state.mood = 'out';

    for (const minutes of [1, 5, 30]) {
      now = minutes * MINUTE_MS;
      assert.deepStrictEqual(await stores.find('sabia', traceFor()), sabia);
      await settled();
    }
    assert.deepStrictEqual(await stores.find('sabia', traceFor()), sabia);
  });

  it('writes an error line when the catalog breaks its contract behind a store it kept', async () => {
    const { catalog, state } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });
    await stores.find('arara', traceFor());
    state.mood = 'broken';
    const errors: string[] = [];

    now += MINUTE_MS;
    assert.deepStrictEqual(await stores.find('arara', traceFor(errors)), arara);
    await settled();
    assert.deepStrictEqual(errors, ['kept a store the catalog could not confirm']);
  });

  it('says it cannot answer for a store it never knew while the catalog is out', async () => {
    const { catalog, state } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });
    state.mood = 'out';

    await assert.rejects(stores.find('arara', traceFor()), ServiceUnavailable);
  });

  it('forgets a store the catalog no longer has', async () => {
    const { catalog, state } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });
    await stores.find('bemtevi', traceFor());
    state.stores = [arara, sabia];

    now += MINUTE_MS;
    await stores.find('bemtevi', traceFor());
    await settled();
    assert.equal(await stores.find('bemtevi', traceFor()), null);
  });

  it('asks the catalog once for a store many screens want at the same time', async () => {
    const { catalog, asked, state } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });
    let open = () => {};
    state.gate = new Promise((resolve) => {
      open = resolve;
    });

    const screens = Array.from({ length: 5 }, () => stores.find('arara', traceFor()));
    open();

    assert.deepStrictEqual(await Promise.all(screens), Array(5).fill(arara));
    assert.deepStrictEqual(asked, ['arara']);
  });

  it('keeps a few stores at most, letting go of the one kept longest ago', async () => {
    const many = Array.from({ length: 4 }, (_, index) => ({ ...arara, slug: `loja-${index}` }));
    const { catalog, asked } = catalogOf(many);
    const stores = storesFrom({ catalog, now: clock, maxKept: 3 });

    for (const store of many) {
      await stores.find(store.slug, traceFor());
    }
    await stores.find('loja-3', traceFor());
    await stores.find('loja-0', traceFor());
    assert.deepStrictEqual(asked, ['loja-0', 'loja-1', 'loja-2', 'loja-3', 'loja-0']);
  });

  it('knows every store of the list without asking for each one of them', async () => {
    const { catalog, asked } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });

    assert.deepStrictEqual(await stores.all(traceFor()), everyStore);
    assert.deepStrictEqual(await stores.find('sabia', traceFor()), sabia);
    assert.deepStrictEqual(await stores.all(traceFor()), everyStore);
    assert.deepStrictEqual(asked, ['list']);
  });

  it('keeps the list of the stores while the catalog is out, once it has seen it', async () => {
    const { catalog, state } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });
    await stores.all(traceFor());
    state.mood = 'out';

    now += 10 * MINUTE_MS;
    assert.deepStrictEqual(await stores.all(traceFor()), everyStore);
  });

  it('never asks the catalog for a slug out of shape', async () => {
    const { catalog, asked } = catalogOf(everyStore);
    const stores = storesFrom({ catalog, now: clock });

    for (const slug of ['', 'A', 'Arara', 'arara livros', '../arara', 'a'.repeat(32)]) {
      assert.equal(await stores.find(slug, traceFor()), null, slug);
    }
    assert.deepStrictEqual(asked, []);
  });
});

describe('two stores, one shopper', () => {
  let services: FakeServices;
  let catalogIsOut = false;
  let payments = 0;
  /** Ana's order in Arara, and the code of its parcel; Sabiá has a parcel of its own. */
  const ORDER = pendingOrder.orderId;
  const IN_ARARA = deliveredParcel.trackingCode;
  const IN_SABIA = 'TX02Q6AGJQ45G00';

  before(async () => {
    services = await fakeServices((app) => {
      app.addHook('onRequest', async (request, reply) => {
        if (catalogIsOut && /^\/v1\/stores(\/[^/]+)?$/.test(request.url)) {
          return reply.code(503).header('retry-after', '4').send({ status: 503 });
        }
      });
      serveStores(app);
      app.get<{ Params: { store: string; customerId: string } }>(
        '/v1/stores/:store/customers/:customerId/orders',
        async (request) => {
          const { store, customerId } = request.params;
          const mine = customerId === ana.id;
          const orders = !mine
            ? []
            : store === 'arara'
              ? [summaryJson(booksDelivered)]
              : store === 'sabia'
                ? [summaryJson(mugsInSabia)]
                : [];
          return { page: 1, perPage: 10, total: orders.length, orders };
        },
      );
      // Commerce keeps each order in one store: through another store it is not found.
      app.get<{ Params: { store: string; customerId: string; orderId: string } }>(
        '/v1/stores/:store/customers/:customerId/orders/:orderId',
        async (request, reply) => {
          const { store, customerId, orderId } = request.params;
          return store === 'arara' && customerId === ana.id && orderId === ORDER
            ? customerOrderJson(pendingOrder, histories.pending)
            : reply.code(404).send({ status: 404 });
        },
      );
      app.post('/v1/orders/:orderId/payments', async (_request, reply) => {
        payments += 1;
        return reply.code(202).send({ paymentId: 'p1', orderId: ORDER, status: 'pending' });
      });
      app.get<{ Params: { code: string } }>('/v1/tracking/:code', async (request, reply) => {
        const store = { [IN_ARARA]: 'arara', [IN_SABIA]: 'sabia' }[request.params.code];
        return store === undefined
          ? reply.code(404).send({ status: 404 })
          : { ...trackingJson({ ...deliveredParcel, trackingCode: request.params.code }), store };
      });
    });
  });

  beforeEach(() => {
    catalogIsOut = false;
  });

  after(() => services.close());

  it('lists the orders of the shopper store by store, each through its own store', async () => {
    const titles = async (store: string) => {
      const response = await bffOver(services).inject({
        method: 'GET',
        url: `/v1/stores/${store}/orders`,
        headers: { cookie: ANA },
      });
      assert.equal(response.statusCode, 200, store);
      assert.equal(
        services.received.at(-1)?.url,
        `/v1/stores/${store}/customers/${ana.id}/orders?page=1&perPage=10`,
      );
      return response
        .json()
        .entities.filter((entity: { rel: string[] }) => entity.rel.includes(rel.item))
        .map((entity: { title: string; links: { href: string }[] }) => [
          entity.title,
          entity.links[0]?.href,
        ]);
    };

    assert.deepStrictEqual(await titles('arara'), [
      [
        `Pedido ${booksDelivered.orderNumber}`,
        `/bff/v1/stores/arara/orders/${booksDelivered.orderId}`,
      ],
    ]);
    assert.deepStrictEqual(await titles('sabia'), [
      [`Pedido ${mugsInSabia.orderNumber}`, `/bff/v1/stores/sabia/orders/${mugsInSabia.orderId}`],
    ]);
    assert.deepStrictEqual(await titles('bemtevi'), []);
  });

  it('answers 404 to an order of one store through the address of another, like an order that does not exist', async () => {
    const own = await bffOver(services).inject({
      method: 'GET',
      url: `/v1/stores/arara/orders/${ORDER}`,
      headers: { cookie: ANA },
    });
    const elsewhere = await bffOver(services).inject({
      method: 'GET',
      url: `/v1/stores/sabia/orders/${ORDER}`,
      headers: { cookie: ANA },
    });
    const nowhere = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/stores/sabia/orders/0199a2b4-0000-7000-8000-000000000000',
      headers: { cookie: ANA },
    });

    assert.equal(own.statusCode, 200);
    assert.equal(elsewhere.statusCode, 404);
    assert.equal(
      services.received.at(-1)?.url,
      `/v1/stores/sabia/customers/${ana.id}/orders/0199a2b4-0000-7000-8000-000000000000`,
    );
    assert.equal(elsewhere.json().detail, nowhere.json().detail);
  });

  it('never pays an order of one store through another', async () => {
    const paymentsSoFar = payments;
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `/v1/stores/sabia/orders/${ORDER}/payments`,
      headers: { cookie: ANA },
      payload: { idempotencyKey: PAY_KEY, cardToken: 'tok_visa' },
    });

    assert.equal(response.statusCode, 404);
    assert.equal(payments, paymentsSoFar);
  });

  it('sends a code typed on the platform to the page in the store of its parcel', async () => {
    const storeOf = async (code: string) => {
      const response = await bffOver(services).inject({
        method: 'GET',
        url: `/v1/tracking?code=${code.toLowerCase()}`,
      });
      assert.equal(response.statusCode, 303, code);
      return response.headers.location;
    };

    assert.equal(await storeOf(IN_ARARA), `/bff/v1/stores/arara/tracking/${IN_ARARA}`);
    assert.equal(await storeOf(IN_SABIA), `/bff/v1/stores/sabia/tracking/${IN_SABIA}`);
  });

  it('answers 404 to a store the catalog does not have, on every screen of a store, before anything else', async () => {
    const received = services.received.length;
    const code = 'TX02PX83TXC5G00';
    for (const request of [
      { method: 'GET' as const, url: '/v1/stores/nope' },
      { method: 'GET' as const, url: '/v1/stores/nope/products?page=0' },
      { method: 'GET' as const, url: '/v1/stores/nope/products/BOOK-DDD-001' },
      { method: 'GET' as const, url: '/v1/stores/nope/checkout?sku=BOOK-DDD-001&quantity=1' },
      { method: 'POST' as const, url: '/v1/stores/nope/orders', payload: orderForm },
      { method: 'GET' as const, url: '/v1/stores/nope/orders' },
      { method: 'GET' as const, url: `/v1/stores/nope/orders/${ORDER}` },
      {
        method: 'POST' as const,
        url: `/v1/stores/nope/orders/${ORDER}/payments`,
        payload: { idempotencyKey: PAY_KEY, cardToken: 'tok_visa' },
      },
      { method: 'GET' as const, url: `/v1/stores/nope/tracking?code=${code}` },
      { method: 'GET' as const, url: `/v1/stores/nope/tracking/${code}` },
      { method: 'GET' as const, url: '/v1/stores/No%20Way/orders' },
    ]) {
      const response = await bffOver(services, { newId: ids(ana.id) }).inject({
        ...request,
        headers: { cookie: ANA },
      });

      assert.equal(response.statusCode, 404, request.url);
      assert.equal(response.json().detail, 'Não encontrei essa loja.', request.url);
      assert.equal(response.headers['set-cookie'], undefined, request.url);
    }
    // The catalog was asked about the store, and nobody was asked anything else.
    assert.deepStrictEqual(
      [...new Set(services.received.slice(received).map(({ url }) => url))],
      ['/v1/stores/nope'],
    );
  });

  it('takes the profiles back to the store they were opened from', async () => {
    const opened = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/profiles?store=sabia',
      headers: { cookie: ANA },
    });
    const up = opened.json().links.find((link: { rel: string[] }) => link.rel.includes('up'));
    const created = await bffOver(services, {
      newId: ids('0199a2b4-6a01-7b02-8c03-d4e5f6a7b8c1'),
    }).inject({
      method: 'POST',
      url: '/v1/profiles?store=sabia',
      headers: { cookie: ANA },
      payload: { name: 'Carla' },
    });
    const switched = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/profiles/active?store=arara',
      headers: { cookie: ANA },
      payload: { profileId: anaShopping.profiles[1]?.id },
    });

    assert.deepStrictEqual(up, {
      rel: ['up'],
      href: '/bff/v1/stores/sabia',
      title: 'Sabiá Casa e Esporte',
    });
    assert.equal(created.statusCode, 303);
    assert.equal(created.headers.location, '/bff/v1/stores/sabia/orders');
    assert.equal(switched.statusCode, 303);
    assert.equal(switched.headers.location, '/bff/v1/stores/arara/orders');
  });

  it('keeps the screens of a store it knows open while the catalog is out, and the profiles too', async () => {
    let now = 0;
    const bff = bffOver(services, { clock: () => now });
    const orders = () =>
      bff.inject({ method: 'GET', url: '/v1/stores/arara/orders', headers: { cookie: ANA } });
    assert.equal((await orders()).statusCode, 200);

    catalogIsOut = true;
    now += 5 * MINUTE_MS;
    const kept = await orders();
    const unknown = await bff.inject({ method: 'GET', url: '/v1/stores/sabia/orders' });
    const profiles = await bff.inject({
      method: 'GET',
      url: '/v1/profiles?store=bemtevi',
      headers: { cookie: ANA },
    });

    assert.equal(kept.statusCode, 200);
    assert.equal(unknown.statusCode, 503);
    assert.equal(unknown.headers['retry-after'], '4');
    assert.equal(
      unknown.json().detail,
      'Agora não consegui falar com o catálogo. Tente de novo em 4 segundos.',
    );
    // The profiles never wait for the catalog: without it, they go back to the start.
    assert.equal(profiles.statusCode, 200);
    assert.equal(
      profiles.json().links.find((link: { rel: string[] }) => link.rel.includes('up')).href,
      '/bff/v1',
    );
  });

  it('places the order in the store of its address, with the profile that shops in every store', async () => {
    const placed: unknown[] = [];
    const commerce = await fakeServices((app) => {
      serveStores(app);
      app.post('/v1/orders', async (request, reply) => {
        placed.push(request.body);
        return reply.code(422).send({ status: 422, errors: { 'items.0.sku': ['elsewhere'] } });
      });
    });
    try {
      await bffOver(commerce).inject({
        method: 'POST',
        url: '/v1/stores/sabia/orders',
        headers: { cookie: ANA },
        payload: orderForm,
      });

      assert.partialDeepStrictEqual(placed, [{ store: 'sabia', customer: { id: ana.id } }]);
    } finally {
      await commerce.close();
    }
  });
});
