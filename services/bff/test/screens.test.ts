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
import { catalogScreen, homeScreen, productScreen } from '../src/storefront/index.ts';
import { trackingScreen } from '../src/tracking/index.ts';
import type { Order, OrderSummary, Tracking } from '../src/upstream/index.ts';
import { EXAMPLE_SCREENS, LIVE_PATH, PAY_KEY } from './support/example-screens.ts';
import { example, wire } from './support/examples.ts';
import {
  anaShopping,
  booksExpired,
  dddBook,
  deliveredOrder,
  deliveredParcel,
  histories,
  mugsDelivered,
  outForDeliveryOwnFleet,
  parcelDelivered,
  parcelInTransit,
  parcelWithTheCourier,
  pendingOrder,
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
      const screen = orderScreen(story({ ...pendingOrder, status }), WAITING);
      assert.deepStrictEqual(screen.class, ['screen', 'order'], status);
      assert.equal(screen.properties?.refreshAfterSeconds, undefined, status);
    }
  });

  it('keeps following the payment while the order is prepared, and lets go when it ships', () => {
    const view: OrderView = { awaitingPayment: true, key: PAY_KEY };
    const preparing = orderScreen(story({ ...pendingOrder, status: 'paid' }), view);
    const shipped = orderScreen(story(shippedOrder), view);

    assert.equal(
      preparing.links?.[0]?.href,
      `/bff/v1/orders/${pendingOrder.orderId}?awaiting=payment`,
    );
    assert.equal(preparing.properties?.reservationExpiresAt, null);
    assert.equal(shipped.links?.[0]?.href, `/bff/v1/orders/${pendingOrder.orderId}`);
    assert.equal(shipped.properties?.notice, undefined);
  });

  it('offers the pay form only while the order waits and nobody is paying yet', () => {
    const waiting = orderScreen(story(pendingOrder), WAITING);
    const confirming = orderScreen(story(pendingOrder), { awaitingPayment: true, key: PAY_KEY });
    const paid = orderScreen(story({ ...pendingOrder, status: 'paid' }), WAITING);

    assert.deepStrictEqual(
      waiting.actions?.map((action) => action.name),
      ['pay'],
    );
    assert.equal(confirming.actions, undefined);
    assert.equal(paid.actions, undefined);
  });

  it('tells a returned order that the refund was asked for', () => {
    const screen = orderScreen(
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
    const screen = orderScreen(story({ ...pendingOrder, status: 'cancelled' }), {
      awaitingPayment: true,
      key: PAY_KEY,
    });

    assert.equal(screen.properties?.notice, undefined);
    assert.equal(screen.links?.[0]?.href, '/bff/v1/orders/0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d20');
  });

  it('takes the way back to the orders of the shopper', () => {
    const screen = orderScreen(story(pendingOrder), WAITING);

    assert.deepStrictEqual(
      screen.links?.find((link) => link.rel.includes(rel.collection)),
      { rel: [rel.collection], href: '/bff/v1/orders', title: 'Meus pedidos' },
    );
  });

  it('says where the parcel is, and with whom, while the order is on its way', () => {
    const headline = (tracking: Tracking): unknown =>
      orderScreen(story(shippedOrder, known(tracking)), WAITING).properties?.headline;

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
      orderScreen(story(shippedOrder), WAITING).properties?.headline,
      'Seu pedido está a caminho.',
    );
  });

  it('offers the courier live under the rule of the tracking page, and only then', () => {
    const live = (tracking: Tracking) =>
      orderScreen(story(shippedOrder, known(tracking)), WAITING).links?.find((link) =>
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
    const screen = orderScreen(
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
    const screen = orderScreen(
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

  it('keeps the moves of the order when the parcel has no news to add', () => {
    const screen = orderScreen(
      { order: deliveredOrder, history: histories.delivered, delivery: { news: 'unavailable' } },
      WAITING,
    );

    assert.deepStrictEqual(
      historyOf(screen).map((step) => step?.label),
      ['Pedido feito', 'Pagamento aprovado', 'Pedido enviado', 'Pedido entregue'],
    );
  });

  it('starts with the order being placed even when the caller knows no more than the order', () => {
    const screen = orderScreen({ order: pendingOrder, history: [], delivery: NO_NEWS }, WAITING);

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
    const screen = orderScreen(
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
        orderScreen(
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
    ordersScreen({ orders: [summary], page: 1, perPage: 10, total: 1 }).entities?.[0];

  it('names what was bought in a line', () => {
    const items = (lines: OrderSummary['lines']) =>
      card({ ...mugsDelivered, lines })?.properties?.itemsLabel;
    const mug = { sku: 'HOME-MUG-001', name: 'Caneca de cerâmica', quantity: 1 };
    const book = { sku: 'BOOK-DDD-001', name: 'Domain-Driven Design', quantity: 1 };

    assert.equal(items([mug]), 'Caneca de cerâmica');
    assert.equal(items([{ ...mug, quantity: 2 }]), '2x Caneca de cerâmica');
    assert.equal(items([book, mug]), 'Domain-Driven Design e mais 1 item');
    assert.equal(items([book, mug, mug]), 'Domain-Driven Design e mais 2 itens');
  });

  it('links each order and heads it with its number', () => {
    const summary = card(booksExpired);

    assert.equal(summary?.title, 'Pedido 97856089876543488');
    assert.deepStrictEqual(summary?.links, [
      { rel: ['self'], href: '/bff/v1/orders/0199a1e8-0a1b-7c2d-9e3f-4a5b6c7d8e9f' },
    ]);
  });

  it('links the pages around the current one', () => {
    const middle = ordersScreen({ orders: [mugsDelivered], page: 2, perPage: 1, total: 3 });

    assert.deepStrictEqual(
      middle.links?.map((link) => [link.rel[0], link.href]),
      [
        ['self', '/bff/v1/orders?page=2'],
        ['next', '/bff/v1/orders?page=3'],
        ['prev', '/bff/v1/orders?page=1'],
        [rel.catalog, '/bff/v1/products'],
      ],
    );
  });
});

describe('the profiles and the navigation', () => {
  it('offer the switch on every profile but the one shopping', () => {
    const screen = profilesScreen(anaShopping);

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
    const screen = profilesScreen(null);

    assert.equal(screen.entities, undefined);
    assert.deepStrictEqual(
      screen.actions?.map((action) => action.name),
      ['create-profile'],
    );
  });

  it('name the shopper in the header, or invite to come in', () => {
    const titleOfProfiles = (entity: Entity) =>
      entity.links?.find((link) => link.rel.includes(rel.profiles))?.title;

    assert.equal(titleOfProfiles(navigationOf(null)), 'Entrar');
    assert.equal(titleOfProfiles(navigationOf(visitorShopping)), 'Visitante');
    assert.deepStrictEqual(navigationOf(anaShopping).properties, {
      shopper: { profileId: anaShopping.active, label: 'Ana', initial: 'A' },
    });
  });

  it('add the navigation after the entities of the screen, and change nothing else', () => {
    const screen = homeScreen();
    const navigation = navigationOf(null);

    assert.deepStrictEqual(withNavigation(screen, navigation), {
      ...screen,
      entities: [navigation],
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
    const screen = trackingScreen(moving, LIVE_PATH);

    assert.deepStrictEqual(screen.class, ['screen', 'tracking', 'live']);
    assert.equal(screen.properties?.refreshAfterSeconds, 5);
  });

  it('says why a visit did not deliver', () => {
    const last = trackingScreen(moving, LIVE_PATH).entities?.at(-1);

    assert.deepStrictEqual(last?.properties, {
      status: 'delivery_failed',
      label: 'Entrega não realizada',
      at: '2026-09-28T00:50:19.827Z',
      attempt: 1,
      detail: 'Ninguém estava em casa.',
    });
  });

  it('shows the code of a carrier it has no name for yet', () => {
    assert.equal(trackingScreen(moving, LIVE_PATH).properties?.carrierLabel, 'mula-rapida');
  });

  it('offers to watch the courier live for the own fleet out for delivery', () => {
    const screen = trackingScreen(outForDeliveryOwnFleet, LIVE_PATH);

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
    const screen = trackingScreen(partner, LIVE_PATH);

    assert.equal(
      screen.links?.some((link) => link.rel.includes(rel.live)),
      false,
    );
  });

  it('never offers the live link for the own fleet outside out_for_delivery', () => {
    for (const status of ['picked_up', 'in_transit', 'delivered', 'delivery_failed'] as const) {
      const screen = trackingScreen({ ...outForDeliveryOwnFleet, status }, LIVE_PATH);

      assert.equal(
        screen.links?.some((link) => link.rel.includes(rel.live)),
        false,
        status,
      );
    }
  });
});

describe('the storefront screens', () => {
  it('link the pages around the current one', () => {
    const middle = catalogScreen({ products: [dddBook], page: 2, perPage: 1, total: 3 });

    assert.deepStrictEqual(
      middle.links?.map((link) => [link.rel[0], link.href]),
      [
        ['self', '/bff/v1/products?page=2'],
        ['next', '/bff/v1/products?page=3'],
        ['prev', '/bff/v1/products?page=1'],
        ['up', '/bff/v1'],
      ],
    );
  });

  it('show a product out of line without the buy action', () => {
    const screen = productScreen({ ...dddBook, status: 'discontinued' });

    assert.equal(screen.actions, undefined);
    assert.deepStrictEqual(screen.properties?.notice, {
      tone: 'neutral',
      text: 'Este produto saiu de linha e não está mais à venda.',
    });
  });
});
