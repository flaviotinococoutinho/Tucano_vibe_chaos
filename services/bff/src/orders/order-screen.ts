import {
  type Action,
  component,
  type Entity,
  href,
  idempotencyKeyField,
  type Link,
  money,
  type Notice,
  type Option,
  path,
  rel,
  screen,
} from '../hypermedia/index.ts';
import { liveLinks, travelsWith } from '../tracking/index.ts';
import type {
  CancellationReason,
  Order,
  OrderLine,
  OrderTransition,
  ShipmentStatus,
  Tracking,
} from '../upstream/index.ts';
import { CONFIRMING, lookOf, REFRESH_WHILE_MOVING_SECONDS } from './looks.ts';
import { type Delivery, historyOf, progressOf } from './story.ts';

const NOTICES = {
  confirming: {
    tone: 'info',
    text: 'O pagamento foi enviado. A confirmação chega em alguns segundos.',
  },
  approved: { tone: 'success', text: 'Pagamento aprovado.' },
  returned: { tone: 'info', text: 'O estorno do pagamento já foi pedido.' },
  deliveryNewsMissing: {
    tone: 'neutral',
    text: 'Agora não consegui buscar as notícias da entrega. A página tenta de novo sozinha em alguns segundos.',
  },
} as const satisfies Record<string, Notice>;

const WHY_CANCELLED: Readonly<Record<CancellationReason, Notice>> = {
  payment_declined: {
    tone: 'danger',
    text: 'O pagamento foi recusado, e o pedido foi cancelado. O estoque voltou para a prateleira.',
  },
  reservation_expired: {
    tone: 'neutral',
    text: 'O prazo para pagar acabou, e a reserva do estoque foi liberada.',
  },
  customer_request: { tone: 'neutral', text: 'Este pedido foi cancelado a seu pedido.' },
};

/** While the order is on its way, the parcel says where exactly, and with whom. */
const ON_THE_WAY: Readonly<Record<ShipmentStatus, (withCarrier: string) => string>> = {
  created: () => 'Seu pedido está pronto e espera a transportadora.',
  ready_for_pickup: () => 'Seu pedido está pronto e espera a transportadora.',
  picked_up: (withCarrier) => `Seu pedido está a caminho ${withCarrier}.`,
  in_transit: (withCarrier) => `Seu pedido está a caminho ${withCarrier}.`,
  out_for_delivery: (withCarrier) => `Seu pedido saiu para entrega ${withCarrier}.`,
  delivery_failed: () => 'A transportadora não conseguiu entregar desta vez.',
  returning: () => 'Seu pedido está voltando para o nosso centro de distribuição.',
  returned: () => 'Seu pedido voltou para o nosso centro de distribuição.',
  delivered: () => 'Seu pedido foi entregue.',
  cancelled: () => 'O envio deste pedido foi cancelado.',
};

/**
 * The cards of PayFake. They are PSP tokens, never card numbers: the BFF and Commerce
 * never see a PAN, which keeps both out of the PCI DSS scope of card data.
 */
export const TEST_CARDS: readonly Option[] = [
  { value: 'tok_visa', title: 'Cartão de teste que aprova' },
  { value: 'tok_decline', title: 'Cartão de teste recusado' },
  { value: 'tok_insufficient', title: 'Cartão de teste sem saldo' },
];

/** What the order screen tells: the order, the statuses it went through, and its parcel. */
export type OrderStory = {
  readonly order: Order;
  /** Every status the order went through, oldest first; right after placing, none is needed. */
  readonly history: readonly OrderTransition[];
  readonly delivery: Delivery;
};

export type OrderView = {
  /** The customer just paid: the screen follows the payment until the PSP answers. */
  readonly awaitingPayment: boolean;
  /** The idempotency key of the pay form, when the screen offers one. */
  readonly key: string;
};

export function orderScreen(story: OrderStory, view: OrderView): Entity {
  const { order, history, delivery } = story;
  const confirming = view.awaitingPayment && order.status === 'pending_payment';
  // Right after paying, the screen follows the payment until the parcel moves: the approved
  // notice stays while the order is prepared, and self lets go of it once the order ships.
  const followingPayment = confirming || (view.awaitingPayment && order.status === 'paid');
  const look = confirming ? CONFIRMING : lookOf(order.status);
  const tracking = delivery.news === 'known' ? delivery.tracking : null;
  const missingNews = delivery.news === 'unavailable';
  const notice =
    noticeOf(order, view.awaitingPayment) ??
    (missingNews ? NOTICES.deliveryNewsMissing : undefined);
  // Without its delivery news, even a finished order asks again, until logistics answers.
  const refreshAfterSeconds =
    look.refreshAfterSeconds ?? (missingNews ? REFRESH_WHILE_MOVING_SECONDS : undefined);
  const self = href(path`/orders/${order.orderId}`, {
    awaiting: followingPayment ? 'payment' : undefined,
  });

  return screen('order', {
    title: `Pedido ${order.orderNumber}`,
    properties: {
      orderId: order.orderId,
      orderNumber: order.orderNumber,
      status: order.status,
      statusLabel: look.label,
      tone: look.tone,
      headline: headlineOf(order, confirming, tracking),
      placedAt: order.placedAt,
      // The deadline matters while the order waits for payment; after that the stock is sold.
      reservationExpiresAt: order.status === 'pending_payment' ? order.reservationExpiresAt : null,
      total: money(order.total.amount, order.total.currency),
      trackingCode: order.trackingCode,
      progress: progressOf({
        status: order.status,
        placedAt: order.placedAt,
        cancellationReason: order.cancellationReason,
        transitions: history,
        confirming,
        ...preparingSince(tracking),
      }),
      ...(notice === undefined ? {} : { notice }),
    },
    entities: [...order.lines.map(orderLine), ...historyOf(order, history, delivery)],
    // Paying twice is not a thing: the form leaves while the first payment is on its way.
    actions: order.status === 'pending_payment' && !confirming ? [payAction(order, view.key)] : [],
    links: [
      { rel: [rel.self], href: self },
      { rel: [rel.collection], href: href('/orders'), title: 'Meus pedidos' },
      ...trackLink(order),
      ...(delivery.news === 'known' ? liveLinks(delivery.tracking, delivery.livePath) : []),
      catalogLink(),
    ],
    ...(refreshAfterSeconds === undefined ? {} : { refreshAfterSeconds }),
  });
}

/** One sentence, in the voice of the store, that says where the order is now. */
function headlineOf(order: Order, confirming: boolean, tracking: Tracking | null): string {
  switch (order.status) {
    case 'pending_payment':
      return confirming ? 'Estamos confirmando o pagamento.' : 'Estamos esperando o pagamento.';
    case 'paid':
      return 'Estamos preparando o seu pedido para o envio.';
    case 'shipped':
      return tracking === null
        ? 'Seu pedido está a caminho.'
        : ON_THE_WAY[tracking.status](travelsWith(tracking.carrier));
    case 'delivered':
      return 'Seu pedido foi entregue.';
    case 'cancelled':
      return 'Este pedido foi cancelado e não vai ser enviado.';
    case 'returned':
      return 'Seu pedido voltou para o nosso centro de distribuição.';
  }
}

/** Logistics knows when the parcel started being prepared: the step that created the shipment. */
function preparingSince(tracking: Tracking | null): { preparingSince?: string } {
  const created = tracking?.steps.find(({ status }) => status === 'created');
  return created === undefined ? {} : { preparingSince: created.at };
}

function noticeOf(order: Order, awaitingPayment: boolean): Notice | undefined {
  switch (order.status) {
    case 'pending_payment':
      return awaitingPayment ? NOTICES.confirming : undefined;
    case 'cancelled':
      return order.cancellationReason === null
        ? undefined
        : WHY_CANCELLED[order.cancellationReason];
    case 'returned':
      return NOTICES.returned;
    case 'paid':
      // The customer was waiting for the payment, and it went through.
      return awaitingPayment ? NOTICES.approved : undefined;
    default:
      return undefined;
  }
}

function orderLine(line: OrderLine): Entity {
  return component('order-line', {
    rel: [rel.item],
    properties: {
      sku: line.sku,
      name: line.name,
      quantity: line.quantity,
      unitPrice: money(line.unitPrice.amount, line.unitPrice.currency),
      subtotal: money(line.subtotal.amount, line.subtotal.currency),
    },
  });
}

function payAction(order: Order, key: string): Action {
  return {
    name: 'pay',
    title: 'Pagar',
    method: 'POST',
    href: href(path`/orders/${order.orderId}/payments`),
    type: 'application/json',
    fields: [
      idempotencyKeyField(key),
      {
        name: 'cardToken',
        type: 'select',
        title: 'Cartão',
        required: true,
        value: 'tok_visa',
        options: TEST_CARDS,
      },
    ],
  };
}

function trackLink(order: Order): Link[] {
  return order.trackingCode === null
    ? []
    : [
        {
          rel: [rel.track],
          href: href(path`/tracking/${order.trackingCode}`),
          title: 'Acompanhar a entrega',
        },
      ];
}

function catalogLink(): Link {
  return { rel: [rel.catalog], href: href('/products'), title: 'Continuar comprando' };
}
