import assert from 'node:assert/strict';
import { after, before, beforeEach, describe, it } from 'node:test';
import { setTimeout as sleep } from 'node:timers/promises';
import { rel } from '../src/hypermedia/index.ts';
import { bffOver } from './support/bff.ts';
import { example } from './support/examples.ts';
import { type FakeServices, fakeServices, serveStores } from './support/fake-services.ts';
import { sessionCookie } from './support/sessions.ts';
import {
  ana,
  anaShopping,
  booksDelivered,
  booksExpired,
  customerOrderJson,
  histories,
  ownFleetOrder,
  parcelInTransit,
  parcelWithTheCourier,
  shippedOrder,
  summaryJson,
  trackingJson,
} from './support/upstream-data.ts';

const ANA = sessionCookie(anaShopping);
const SHIPPED = shippedOrder.orderId;
const OWN_FLEET = '0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d21';

/** How the fake logistics behaves in each test: answers, takes its time, is out, or knows nothing yet. */
type LogisticsMood = 'answers' | 'slow' | 'out' | 'no-news';

describe('my orders', () => {
  let services: FakeServices;
  let listIsOut = false;

  before(async () => {
    services = await fakeServices((app) => {
      serveStores(app);
      app.get<{ Params: { customerId: string }; Querystring: { page: string; perPage: string } }>(
        '/v1/stores/arara/customers/:customerId/orders',
        async (request, reply) => {
          if (listIsOut) {
            return reply.code(503).header('retry-after', '7').send({ status: 503 });
          }
          return {
            page: Number(request.query.page),
            perPage: Number(request.query.perPage),
            total: 12,
            orders: [summaryJson(booksDelivered), summaryJson(booksExpired)],
          };
        },
      );
    });
  });

  beforeEach(() => {
    listIsOut = false;
  });

  after(() => services.close());

  it('shows an empty list to a browser with no session, asking Commerce nothing and starting nothing', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/stores/arara/orders',
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('orders-empty.json'));
    assert.equal(response.headers['set-cookie'], undefined);
    assert.deepStrictEqual(
      services.received.slice(received).map(({ url }) => url),
      ['/v1/stores/arara'],
    );
  });

  it('asks Commerce for the orders of the profile shopping in the store, a page at a time', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/stores/arara/orders?page=2',
      headers: { cookie: ANA },
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('orders.json'));
    assert.equal(
      services.received.at(-1)?.url,
      `/v1/stores/arara/customers/${ana.id}/orders?page=2&perPage=10`,
    );
  });

  it('refuses a page that cannot exist', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/stores/arara/orders?page=0',
      headers: { cookie: ANA },
    });

    assert.equal(response.statusCode, 422);
  });

  it('says the list is out, and when to try again, while its database is out', async () => {
    listIsOut = true;
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/stores/arara/orders',
      headers: { cookie: ANA },
    });

    assert.equal(response.statusCode, 503);
    assert.equal(response.headers['retry-after'], '7');
    assert.equal(
      response.json().detail,
      'A lista de pedidos está fora do ar agora. Tente de novo em 7 segundos.',
    );
  });
});

describe('the story of an order', () => {
  let services: FakeServices;
  let mood: LogisticsMood = 'answers';

  before(async () => {
    services = await fakeServices((app) => {
      serveStores(app);
      app.get<{ Params: { customerId: string; orderId: string } }>(
        '/v1/stores/arara/customers/:customerId/orders/:orderId',
        async (request, reply) => {
          const { customerId, orderId } = request.params;
          if (customerId === ana.id && orderId === SHIPPED) {
            return customerOrderJson(shippedOrder, histories.shipped);
          }
          if (customerId === ana.id && orderId === OWN_FLEET) {
            return customerOrderJson({ ...ownFleetOrder, orderId: OWN_FLEET }, histories.shipped);
          }
          return reply.code(404).send({ status: 404 });
        },
      );
      app.get<{ Params: { code: string } }>(
        '/v1/stores/arara/tracking/:code',
        async (request, reply) => {
          if (mood === 'out') {
            return reply.code(503).header('retry-after', '3').send({ status: 503 });
          }
          if (mood === 'no-news') {
            return reply.code(404).send({ status: 404 });
          }
          if (mood === 'slow') {
            await sleep(400);
          }
          const parcel = [parcelInTransit, parcelWithTheCourier].find(
            ({ trackingCode }) => trackingCode === request.params.code,
          );
          return parcel === undefined
            ? reply.code(404).send({ status: 404 })
            : trackingJson(parcel);
        },
      );
    });
  });

  beforeEach(() => {
    mood = 'answers';
  });

  after(() => services.close());

  const open = (orderId: string) =>
    bffOver(services, {
      env: { UPSTREAM_TIMEOUT_MS: '2000', ENRICHMENT_TIMEOUT_MS: '100' },
    }).inject({
      method: 'GET',
      url: `/v1/stores/arara/orders/${orderId}`,
      headers: { cookie: ANA },
    });

  it('merges the steps of the parcel into the history of the order', async () => {
    const response = await open(SHIPPED);

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('order-shipped.json'));
  });

  it('offers the courier live on the order, while the own fleet is on the way to the door', async () => {
    const response = await open(OWN_FLEET);
    const live = response
      .json()
      .links.find((link: { rel: string[] }) => link.rel.includes(rel.live));

    assert.equal(response.statusCode, 200);
    assert.equal(live.href, '/api/tracking/v1/live?trackingCode=TX02Q6AGJQ45G00');
  });

  it('opens without the news of a slow logistics, long before the deadline of the order', async () => {
    mood = 'slow';
    const startedAt = performance.now();
    const response = await open(SHIPPED);
    const elapsedMs = performance.now() - startedAt;

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('order-without-delivery-news.json'));
    assert.ok(elapsedMs < 1000, `the order took ${Math.round(elapsedMs)} ms`);
  });

  it('opens with its own history while logistics is out', async () => {
    mood = 'out';
    const response = await open(SHIPPED);

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('order-without-delivery-news.json'));
    assert.equal(response.headers['retry-after'], undefined);
  });

  it('adds nothing and warns of nothing while logistics has no news of the code yet', async () => {
    mood = 'no-news';
    const response = await open(SHIPPED);
    const { properties } = response.json();

    assert.equal(response.statusCode, 200);
    assert.equal(properties.notice, undefined);
    assert.equal(properties.headline, 'Seu pedido está a caminho.');
  });

  it('answers 404 to an order the shopper does not have', async () => {
    const response = await open('0199a2b4-0000-7000-8000-000000000000');

    assert.equal(response.statusCode, 404);
  });
});
