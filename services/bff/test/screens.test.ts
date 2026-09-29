import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { describe, it } from 'node:test';
import { type Entity, rel } from '../src/hypermedia/index.ts';
import {
  type Delivery,
  type OrderStory,
  type OrderView,
  orderScreen,
  ordersScreen,
  progressOf,
} from '../src/orders/index.ts';
import { navigationOf, profilesScreen, withNavigation } from '../src/profiles/index.ts';
import { catalogScreen, homeScreen, productScreen, storeScreen } from '../src/storefront/index.ts';
import { trackingScreen } from '../src/tracking/index.ts';
import type { Order, OrderSummary, Tracking } from '../src/upstream/index.ts';
import { EXAMPLE_SCREENS, LIVE_PATH, PAY_KEY } from './support/example-screens.ts';
import { example, wire } from './support/examples.ts';
import {
  anaShopping,
  arara,
  booksDelivered,
  booksExpired,
  dddBook,
  deliveredOrder,
  deliveredParcel,
  everyStore,
  histories,
  outForDeliveryOwnFleet,
  parcelDelivered,
  parcelInTransit,
  parcelWithTheCourier,
  pendingOrder,
  sabia,
  shippedOrder,
  visitorShopping,
} from './support/upstream-data.ts';

const EXAMPLES = new URL('../../../contracts/http/bff/examples/', import.meta.url);

const WAITING: OrderView = { awaitingPayment: false, key: PAY_KEY };
const NO_NEWS: Delivery = { news: 'none' };

function known(tracking: Tracking): Delivery {
  return { news: 'known', tracking, livePath: LIVE_PATH };
}

function story(order: Order, delivery: Delivery = NO_NEWS): OrderStory {
  return { order, history: histories.pending, delivery };
}

/** The order screen of Arara, where every order of these tests is. */
function orderIn(orderStory: OrderStory, view: OrderView): Entity {
  return orderScreen(arara, orderStory, view);
}

/** Each link as its relation and its address, the relations of the contract by their anchor. */
function addresses(links: Entity['links']): string[][] {
  return (links ?? []).map((link) => [link.rel[0]?.split('#')[1] ?? link.rel[0] ?? '', link.href]);
}

function historyOf(screen: Entity): Array<Readonly<Record<string, unknown>> | undefined> {
  return (screen.entities ?? [])
    .filter((entity) => entity.rel?.includes(rel.history))
    .map((entity) => entity.properties);
}

/**
 * The examples of the contract are what the web renders in its tests. Each one here is
 * built by the same function the BFF answers with, so the contract, the BFF and the web
 * cannot drift apart without a test going red.
 */
describe('the screens of the contract', () => {
  for (const [file, build] of Object.entries(EXAMPLE_SCREENS)) {
    it(file, () => {
      assert.deepStrictEqual(wire(build()), example(file));
    });
  }

  it('build every example of the folder, so none of them is a picture that drifted', () => {
    const files = readdirSync(EXAMPLES).filter((file) => file.endsWith('.json'));

    assert.deepStrictEqual(files.sort(), Object.keys(EXAMPLE_SCREENS).sort());
  });
});

describe('the order screen', () => {
  it('stops asking for news when the story ends', () => {
    for (const status of ['delivered', 'cancelled', 'returned'] as const) {
      const screen = orderIn(story({ ...pendingOrder, status }), WAITING);
      assert.deepStrictEqual(screen.class, ['screen', 'order'], status);
      assert.equal(screen.properties?.refreshAfterSeconds, undefined, status);
    }
  });

  it('keeps following the payment while the order is prepared, and lets go when it ships', () => {
    const view: OrderView = { awaitingPayment: true, key: PAY_KEY };
    const preparing = orderIn(story({ ...pendingOrder, status: 'paid' }), view);
    const shipped = orderIn(story(shippedOrder), view);

    assert.equal(
      preparing.links?.[0]?.href,
      `/bff/v1/stores/arara/orders/${pendingOrder.orderId}?awaiting=payment`,
    );
    assert.equal(preparing.properties?.reservationExpiresAt, null);
    assert.equal(shipped.links?.[0]?.href, `/bff/v1/stores/arara/orders/${pendingOrder.orderId}`);
    assert.equal(shipped.properties?.notice, undefined);
  });

  it('offers the pay form only while the order waits and nobody is paying yet', () => {
    const waiting = orderIn(story(pendingOrder), WAITING);
    const confirming = orderIn(story(pendingOrder), { awaitingPayment: true, key: PAY_KEY });
    const paid = orderIn(story({ ...pendingOrder, status: 'paid' }), WAITING);

    assert.deepStrictEqual(
      waiting.actions?.map((action) => action.name),
      ['pay'],
    );
    assert.equal(confirming.actions, undefined);
    assert.equal(paid.actions, undefined);
  });

  it('tells a returned order that the refund was asked for', () => {
    const screen = orderIn(
      story({ ...pendingOrder, status: 'returned', trackingCode: 'TX02PX83TXC5G00' }),
      WAITING,
    );

    assert.deepStrictEqual(screen.properties?.notice, {
      tone: 'info',
      text: 'O estorno do pagamento já foi pedido.',
    });
    assert.equal(
      screen.properties?.headline,
      'Seu pedido voltou para o nosso centro de distribuição.',
    );
  });

  it('says nothing about a cancellation whose reason Commerce did not send', () => {
    const screen = orderIn(story({ ...pendingOrder, status: 'cancelled' }), {
      awaitingPayment: true,
      key: PAY_KEY,
    });

    assert.equal(screen.properties?.notice, undefined);
    assert.equal(
      screen.links?.[0]?.href,
      '/bff/v1/stores/arara/orders/0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d20',
    );
  });

  it('takes the way back to the orders of the shopper in the store', () => {
    const screen = orderIn(story(pendingOrder), WAITING);

    assert.deepStrictEqual(
      screen.links?.find((link) => link.rel.includes(rel.collection)),
      { rel: [rel.collection], href: '/bff/v1/stores/arara/orders', title: 'Meus pedidos' },
    );
  });

  it('keeps every address of the order in its store: the payment, the tracking, the catalog', () => {
    const order = { ...pendingOrder, trackingCode: 'TX02PX83TXC5G00' };
    const screen = orderScreen(sabia, story(order), WAITING);

    assert.deepStrictEqual(addresses(screen.links), [
      ['self', '/bff/v1/stores/sabia/orders/0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d20'],
      ['collection', '/bff/v1/stores/sabia/orders'],
      ['rel-track', '/bff/v1/stores/sabia/tracking/TX02PX83TXC5G00'],
      ['rel-catalog', '/bff/v1/stores/sabia/products'],
    ]);
    assert.equal(
      screen.actions?.[0]?.href,
      '/bff/v1/stores/sabia/orders/0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d20/payments',
    );
  });

  it('says where the parcel is, and with whom, while the order is on its way', () => {
    const headline = (tracking: Tracking): unknown =>
      orderIn(story(shippedOrder, known(tracking)), WAITING).properties?.headline;

    assert.equal(headline(parcelInTransit), 'Seu pedido está a caminho com o Correio Nacional.');
    assert.equal(
      headline(parcelWithTheCourier),
      'Seu pedido saiu para entrega com a Tucano Express.',
    );
    assert.equal(
      headline({ ...parcelInTransit, carrier: 'mula-rapida' }),
      'Seu pedido está a caminho com a transportadora mula-rapida.',
    );
    assert.equal(
      headline({ ...parcelInTransit, status: 'delivery_failed' }),
      'A transportadora não conseguiu entregar desta vez.',
    );
    assert.equal(
      orderIn(story(shippedOrder), WAITING).properties?.headline,
      'Seu pedido está a caminho.',
    );
  });

  it('offers the courier live under the rule of the tracking page, and only then', () => {
    const live = (tracking: Tracking) =>
      orderIn(story(shippedOrder, known(tracking)), WAITING).links?.find((link) =>
        link.rel.includes(rel.live),
      );

    assert.deepStrictEqual(live(parcelWithTheCourier), {
      rel: [rel.live],
      href: '/api/tracking/v1/live?trackingCode=TX02Q6AGJQ45G00',
      title: 'Ver o entregador ao vivo',
    });
    assert.equal(live({ ...parcelWithTheCourier, carrier: 'ligeirinho' }), undefined);
    assert.equal(live(parcelInTransit), undefined);
  });

  it('keeps asking for the delivery news, even of a finished order, until logistics answers', () => {
    const screen = orderIn(
      { order: deliveredOrder, history: histories.delivered, delivery: { news: 'unavailable' } },
      WAITING,
    );

    assert.deepStrictEqual(screen.class, ['screen', 'order', 'live']);
    assert.equal(screen.properties?.refreshAfterSeconds, 5);
    assert.deepStrictEqual(screen.properties?.notice, {
      tone: 'neutral',
      text: 'Agora não consegui buscar as notícias da entrega. A página tenta de novo sozinha em alguns segundos.',
    });
  });
});

describe('the history of an order', () => {
  it('merges the steps of the parcel, oldest first, without what they already tell', () => {
    const screen = orderIn(
      { order: deliveredOrder, history: histories.delivered, delivery: known(parcelDelivered) },
      WAITING,
    );
    const steps = historyOf(screen);

    assert.deepStrictEqual(
      steps.map((step) => step?.status),
      [
        'pending_payment',
        'paid',
        'created',
        'ready_for_pickup',
        'picked_up',
        'in_transit',
        'out_for_delivery',
        'delivered',
      ],
    );
    const instants = steps.map((step) => String(step?.at));
    assert.deepStrictEqual(instants, [...instants].sort());
  });

  it('keeps the moves of the order that a lagging tracking page has not told yet', () => {
    // Commerce already knows the order was delivered; the copy of the tracking page is still
    // at the hub. The order's own delivery stays, and the pickup the parcel told replaces the
    // order's shipped.
    const screen = orderIn(
      { order: deliveredOrder, history: histories.delivered, delivery: known(parcelInTransit) },
      WAITING,
    );

    assert.deepStrictEqual(
      historyOf(screen).map((step) => step?.status),
      [
        'pending_payment',
        'paid',
        'created',
        'ready_for_pickup',
        'picked_up',
        'in_transit',
        'delivered',
      ],
    );
    assert.equal(historyOf(screen).at(-1)?.label, 'Pedido entregue');
  });

  it('keeps the moves of the order when the parcel has no news to add', () => {
    const screen = orderIn(
      { order: deliveredOrder, history: histories.delivered, delivery: { news: 'unavailable' } },
      WAITING,
    );

    assert.deepStrictEqual(
      historyOf(screen).map((step) => step?.label),
      ['Pedido feito', 'Pagamento aprovado', 'Pedido enviado', 'Pedido entregue'],
    );
  });

  it('starts with the order being placed even when the caller knows no more than the order', () => {
    const screen = orderIn({ order: pendingOrder, history: [], delivery: NO_NEWS }, WAITING);

    assert.deepStrictEqual(historyOf(screen), [
      { status: 'pending_payment', label: 'Pedido feito', at: pendingOrder.placedAt },
    ]);
  });

  it('says why a visit did not deliver', () => {
    const failed: Tracking = {
      ...parcelInTransit,
      status: 'delivery_failed',
      steps: [
        ...parcelInTransit.steps,
        {
          status: 'delivery_failed',
          at: '2026-09-28T05:10:29.000Z',
          hub: null,
          attempt: 1,
          reason: 'recipient_absent',
        },
      ],
    };
    const screen = orderIn(
      { order: shippedOrder, history: histories.shipped, delivery: known(failed) },
      WAITING,
    );

    assert.deepStrictEqual(historyOf(screen).at(-1), {
      status: 'delivery_failed',
      label: 'Entrega não realizada',
      at: '2026-09-28T05:10:29.000Z',
      attempt: 1,
      detail: 'Ninguém estava em casa.',
    });
  });

  it('says why an order was cancelled, and nothing for a reason it has no words for', () => {
    const cancelledBecause = (reason: string) =>
      historyOf(
        orderIn(
          {
            order: { ...pendingOrder, status: 'cancelled' },
            history: [
              ...histories.pending,
              { status: 'cancelled', at: '2026-09-28T05:25:11.000Z', reason },
            ],
            delivery: NO_NEWS,
          },
          WAITING,
        ),
      ).at(-1)?.detail;

    assert.equal(cancelledBecause('reservation_expired'), 'O prazo para pagar acabou.');
    assert.equal(cancelledBecause('constructor'), undefined);
    assert.equal(cancelledBecause('fraud_suspected'), undefined);
  });
});

describe('the progress of an order', () => {
  const states = (facts: Parameters<typeof progressOf>[0]) =>
    progressOf(facts).map(({ label, state }) => `${state} ${label}`);
  const base = {
    placedAt: pendingOrder.placedAt,
    cancellationReason: null,
    transitions: histories.pending,
  };

  it('waits for the payment, and says when it is being confirmed', () => {
    assert.deepStrictEqual(states({ ...base, status: 'pending_payment' }), [
      'done Pedido feito',
      'current Aguardando pagamento',
      'upcoming Preparando o envio',
      'upcoming A caminho',
      'upcoming Entregue',
    ]);
    assert.equal(
      progressOf({ ...base, status: 'pending_payment', confirming: true })[1]?.label,
      'Confirmando o pagamento',
    );
  });

  it('gives the time only of what is done', () => {
    const progress = progressOf({ ...base, status: 'paid', transitions: histories.paid });

    assert.deepStrictEqual(progress, [
      { label: 'Pedido feito', state: 'done', at: '2026-09-28T05:10:11.000Z' },
      { label: 'Pagamento aprovado', state: 'done', at: '2026-09-28T05:10:13.482Z' },
      { label: 'Preparando o envio', state: 'current' },
      { label: 'A caminho', state: 'upcoming' },
      { label: 'Entregue', state: 'upcoming' },
    ]);
  });

  it('ends a cancelled order at a stopped milestone named by its reason, and nothing after', () => {
    const cancelled = (reason: 'payment_declined' | 'reservation_expired' | 'customer_request') =>
      states({
        ...base,
        status: 'cancelled',
        cancellationReason: reason,
        transitions: [
          ...histories.pending,
          { status: 'cancelled', at: '2026-09-28T05:25:11.000Z', reason },
        ],
      });

    assert.deepStrictEqual(cancelled('payment_declined'), [
      'done Pedido feito',
      'stopped Pagamento recusado',
    ]);
    assert.deepStrictEqual(cancelled('reservation_expired'), [
      'done Pedido feito',
      'stopped Prazo para pagar acabou',
    ]);
    assert.deepStrictEqual(cancelled('customer_request'), [
      'done Pedido feito',
      'stopped Cancelado a seu pedido',
    ]);
  });

  it('keeps the payment of an order cancelled after it was paid', () => {
    assert.deepStrictEqual(
      states({
        ...base,
        status: 'cancelled',
        cancellationReason: 'customer_request',
        transitions: [
          ...histories.paid,
          { status: 'cancelled', at: '2026-09-28T05:12:00.000Z', reason: 'customer_request' },
        ],
      }),
      ['done Pedido feito', 'done Pagamento aprovado', 'stopped Cancelado a seu pedido'],
    );
  });

  it('ends a returned order at "Devolvido", after the way, without the delivery', () => {
    assert.deepStrictEqual(
      states({
        ...base,
        status: 'returned',
        transitions: [
          ...histories.shipped,
          { status: 'returned', at: '2026-09-28T06:00:00.000Z', reason: null },
        ],
      }),
      [
        'done Pedido feito',
        'done Pagamento aprovado',
        'done Preparando o envio',
        'done A caminho',
        'stopped Devolvido',
      ],
    );
  });

  it('has every milestone done once the order is delivered', () => {
    assert.deepStrictEqual(
      states({ ...base, status: 'delivered', transitions: histories.delivered }),
      [
        'done Pedido feito',
        'done Pagamento aprovado',
        'done Preparando o envio',
        'done A caminho',
        'done Entregue',
      ],
    );
  });
});

describe('the list of orders', () => {
  const card = (summary: OrderSummary) =>
    ordersScreen(arara, { orders: [summary], page: 1, perPage: 10, total: 1 }).entities?.[0];

  it('names what was bought in a line', () => {
    const items = (lines: OrderSummary['lines']) =>
      card({ ...booksDelivered, lines })?.properties?.itemsLabel;
    const release = { sku: 'BOOK-REL-001', name: 'Release It!', quantity: 1 };
    const book = { sku: 'BOOK-DDD-001', name: 'Domain-Driven Design', quantity: 1 };

    assert.equal(items([release]), 'Release It!');
    assert.equal(items([{ ...release, quantity: 2 }]), '2x Release It!');
    assert.equal(items([book, release]), 'Domain-Driven Design e mais 1 item');
    assert.equal(items([book, release, release]), 'Domain-Driven Design e mais 2 itens');
  });

  it('links each order in its store and heads it with its number', () => {
    const summary = card(booksExpired);

    assert.equal(summary?.title, 'Pedido 97856089876543488');
    assert.deepStrictEqual(summary?.links, [
      {
        rel: ['self'],
        href: '/bff/v1/stores/arara/orders/0199a1e8-0a1b-7c2d-9e3f-4a5b6c7d8e9f',
      },
    ]);
  });

  it('links the pages around the current one, in the store', () => {
    const middle = ordersScreen(arara, {
      orders: [booksDelivered],
      page: 2,
      perPage: 1,
      total: 3,
    });

    assert.deepStrictEqual(addresses(middle.links), [
      ['self', '/bff/v1/stores/arara/orders?page=2'],
      ['next', '/bff/v1/stores/arara/orders?page=3'],
      ['prev', '/bff/v1/stores/arara/orders?page=1'],
      ['rel-catalog', '/bff/v1/stores/arara/products'],
    ]);
  });
});

describe('the profiles and the navigation', () => {
  it('offer the switch on every profile but the one shopping', () => {
    const screen = profilesScreen(anaShopping, null);

    assert.deepStrictEqual(
      screen.entities?.map((profile) => [
        profile.properties?.label,
        profile.properties?.active,
        profile.actions?.map((action) => action.title),
      ]),
      [
        ['Ana', true, undefined],
        ['Bruno', false, ['Comprar como Bruno']],
        ['Visitante', false, ['Comprar como Visitante']],
      ],
    );
  });

  it('show no profile and only the form to create one before any session', () => {
    const screen = profilesScreen(null, null);

    assert.equal(screen.entities, undefined);
    assert.deepStrictEqual(
      screen.actions?.map((action) => action.name),
      ['create-profile'],
    );
  });

  it('go back to the store they were opened from, and so do their actions', () => {
    const screen = profilesScreen(anaShopping, sabia);

    assert.deepStrictEqual(addresses(screen.links), [
      ['self', '/bff/v1/profiles?store=sabia'],
      ['up', '/bff/v1/stores/sabia'],
    ]);
    assert.equal(screen.links?.[1]?.title, 'Sabiá Casa e Esporte');
    assert.deepStrictEqual(
      [
        ...(screen.actions ?? []),
        ...(screen.entities ?? []).flatMap((profile) => profile.actions ?? []),
      ].map((action) => [action.name, action.href]),
      [
        ['create-profile', '/bff/v1/profiles?store=sabia'],
        ['use-profile', '/bff/v1/profiles/active?store=sabia'],
        ['use-profile', '/bff/v1/profiles/active?store=sabia'],
      ],
    );
  });

  it('go back to the start of the platform when opened from it', () => {
    const screen = profilesScreen(anaShopping, null);

    assert.deepStrictEqual(addresses(screen.links), [
      ['self', '/bff/v1/profiles'],
      ['up', '/bff/v1'],
    ]);
    assert.equal(screen.actions?.[0]?.href, '/bff/v1/profiles');
  });

  it('name the shopper in the header, or invite to come in', () => {
    const titleOfProfiles = (entity: Entity) =>
      entity.links?.find((link) => link.rel.includes(rel.profiles))?.title;

    assert.equal(titleOfProfiles(navigationOf(null, null)), 'Entrar');
    assert.equal(titleOfProfiles(navigationOf(visitorShopping, arara)), 'Visitante');
    assert.deepStrictEqual(navigationOf(anaShopping, null).properties, {
      store: null,
      shopper: { profileId: anaShopping.active, label: 'Ana', initial: 'A' },
    });
  });

  it('say which store the person is in, and keep every way of the header in it', () => {
    const navigation = navigationOf(anaShopping, sabia);

    assert.deepStrictEqual(navigation.properties?.store, {
      slug: 'sabia',
      name: 'Sabiá Casa e Esporte',
      palette: 'sabia',
      initial: 'S',
    });
    assert.deepStrictEqual(addresses(navigation.links), [
      ['rel-stores', '/bff/v1'],
      ['rel-store', '/bff/v1/stores/sabia'],
      ['rel-catalog', '/bff/v1/stores/sabia/products'],
      ['rel-orders', '/bff/v1/stores/sabia/orders'],
      ['rel-profiles', '/bff/v1/profiles?store=sabia'],
    ]);
    assert.deepStrictEqual(
      navigation.links?.map((link) => link.title),
      ['Todas as lojas', 'Sabiá Casa e Esporte', 'Catálogo', 'Meus pedidos', 'Ana'],
    );
  });

  it('offer only the stores and the profiles on the platform, which has no catalog of its own', () => {
    assert.deepStrictEqual(addresses(navigationOf(anaShopping, null).links), [
      ['rel-stores', '/bff/v1'],
      ['rel-profiles', '/bff/v1/profiles'],
    ]);
  });

  it('add the navigation after the entities of the screen, and change nothing else', () => {
    const screen = homeScreen(everyStore);
    const navigation = navigationOf(null, null);

    assert.deepStrictEqual(withNavigation(screen, navigation), {
      ...screen,
      entities: [...(screen.entities ?? []), navigation],
    });
  });
});

describe('the tracking screen', () => {
  const moving: Tracking = {
    ...deliveredParcel,
    status: 'delivery_failed',
    carrier: 'mula-rapida',
    steps: [
      ...deliveredParcel.steps.slice(0, 6),
      {
        status: 'delivery_failed',
        at: '2026-09-28T00:50:19.827Z',
        hub: null,
        attempt: 1,
        reason: 'recipient_absent',
      },
    ],
  };

  it('asks for news while the parcel moves', () => {
    const screen = trackingScreen(arara, moving, LIVE_PATH);

    assert.deepStrictEqual(screen.class, ['screen', 'tracking', 'live']);
    assert.equal(screen.properties?.refreshAfterSeconds, 5);
  });

  it('says why a visit did not deliver', () => {
    const last = trackingScreen(arara, moving, LIVE_PATH).entities?.at(-1);

    assert.deepStrictEqual(last?.properties, {
      status: 'delivery_failed',
      label: 'Entrega não realizada',
      at: '2026-09-28T00:50:19.827Z',
      attempt: 1,
      detail: 'Ninguém estava em casa.',
    });
  });

  it('shows the code of a carrier it has no name for yet', () => {
    assert.equal(trackingScreen(arara, moving, LIVE_PATH).properties?.carrierLabel, 'mula-rapida');
  });

  it('offers to watch the courier live for the own fleet out for delivery', () => {
    const screen = trackingScreen(arara, outForDeliveryOwnFleet, LIVE_PATH);

    assert.deepStrictEqual(
      screen.links?.find((link) => link.rel.includes(rel.live)),
      {
        rel: [rel.live],
        href: '/api/tracking/v1/live?trackingCode=TX02Q6AGJQ45G00',
        title: 'Ver o entregador ao vivo',
      },
    );
  });

  it('never offers the live link for a partner carrier out for delivery', () => {
    const partner: Tracking = { ...outForDeliveryOwnFleet, carrier: 'ligeirinho' };
    const screen = trackingScreen(arara, partner, LIVE_PATH);

    assert.equal(
      screen.links?.some((link) => link.rel.includes(rel.live)),
      false,
    );
  });

  it('never offers the live link for the own fleet outside out_for_delivery', () => {
    for (const status of ['picked_up', 'in_transit', 'delivered', 'delivery_failed'] as const) {
      const screen = trackingScreen(arara, { ...outForDeliveryOwnFleet, status }, LIVE_PATH);

      assert.equal(
        screen.links?.some((link) => link.rel.includes(rel.live)),
        false,
        status,
      );
    }
  });
});

describe('the storefront screens', () => {
  it('list the stores on the home of the platform, each with the call to enter it', () => {
    const screen = homeScreen(everyStore);

    assert.deepStrictEqual(
      screen.entities?.map((card) => [
        card.class[0],
        card.properties?.name,
        card.properties?.initial,
        card.links?.[0]?.href,
        card.links?.[0]?.title,
      ]),
      [
        ['store-card', 'Arara Livros', 'A', '/bff/v1/stores/arara', 'Entrar na loja'],
        ['store-card', 'Bem-te-vi Eletrônicos', 'B', '/bff/v1/stores/bemtevi', 'Entrar na loja'],
        ['store-card', 'Sabiá Casa e Esporte', 'S', '/bff/v1/stores/sabia', 'Entrar na loja'],
      ],
    );
    assert.equal(screen.actions?.[0]?.href, '/bff/v1/tracking');
  });

  it('open a store with its name, its way into the catalog and its own tracking by code', () => {
    const screen = storeScreen(sabia);

    assert.equal(screen.title, 'Sabiá Casa e Esporte');
    assert.deepStrictEqual(screen.properties, {
      slug: 'sabia',
      name: 'Sabiá Casa e Esporte',
      tagline: 'Da cozinha ao treino, o que o dia pede.',
      palette: 'sabia',
      initial: 'S',
    });
    assert.deepStrictEqual(addresses(screen.links), [
      ['self', '/bff/v1/stores/sabia'],
      ['rel-catalog', '/bff/v1/stores/sabia/products'],
    ]);
    assert.equal(screen.actions?.[0]?.href, '/bff/v1/stores/sabia/tracking');
  });

  it('link the pages around the current one, in the store', () => {
    const middle = catalogScreen(arara, { products: [dddBook], page: 2, perPage: 1, total: 3 });

    assert.deepStrictEqual(addresses(middle.links), [
      ['self', '/bff/v1/stores/arara/products?page=2'],
      ['next', '/bff/v1/stores/arara/products?page=3'],
      ['prev', '/bff/v1/stores/arara/products?page=1'],
      ['up', '/bff/v1/stores/arara'],
    ]);
    assert.equal(
      middle.entities?.[0]?.links?.[0]?.href,
      '/bff/v1/stores/arara/products/BOOK-DDD-001',
    );
  });

  it('buy a product at the checkout of its store', () => {
    const screen = productScreen(arara, dddBook);

    assert.equal(screen.actions?.[0]?.href, '/bff/v1/stores/arara/checkout');
  });

  it('show a product out of line without the buy action', () => {
    const screen = productScreen(arara, { ...dddBook, status: 'discontinued' });

    assert.equal(screen.actions, undefined);
    assert.deepStrictEqual(screen.properties?.notice, {
      tone: 'neutral',
      text: 'Este produto saiu de linha e não está mais à venda.',
    });
  });
});
