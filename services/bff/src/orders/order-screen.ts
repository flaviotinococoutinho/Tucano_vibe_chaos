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
  type Tone,
} from '../hypermedia/index.ts';
import type { CancellationReason, Order, OrderLine, OrderStatus } from '../upstream/index.ts';

type Look = {
  readonly label: string;
  readonly tone: Tone;
  /** While something is about to happen, the screen asks to be fetched again. */
  readonly refreshAfterSeconds?: number;
};

/**
 * How each status reads. The order refreshes itself while the parcel is being prepared
 * or is on its way, and stops when the story ends: delivered, cancelled, returned.
 */
const LOOKS: Readonly<Record<OrderStatus, Look>> = {
  pending_payment: { label: 'Aguardando pagamento', tone: 'waiting' },
  paid: { label: 'Preparando o envio', tone: 'info', refreshAfterSeconds: 5 },
  shipped: { label: 'A caminho', tone: 'info', refreshAfterSeconds: 5 },
  delivered: { label: 'Entregue', tone: 'success' },
  cancelled: { label: 'Cancelado', tone: 'danger' },
  returned: { label: 'Devolvido', tone: 'neutral' },
};

/** Right after paying: the PSP answers by webhook in a second or two, so the screen asks often. */
const CONFIRMING: Look = {
  label: 'Confirmando o pagamento',
  tone: 'waiting',
  refreshAfterSeconds: 2,
};

const NOTICES = {
  confirming: {
    tone: 'info',
    text: 'O pagamento foi enviado. A confirmação chega em alguns segundos.',
  },
  approved: { tone: 'success', text: 'Pagamento aprovado.' },
  returned: {
    tone: 'info',
    text: 'A encomenda voltou para o nosso centro de distribuição, e o estorno do pagamento já foi pedido.',
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

/**
 * The cards of PayFake. They are PSP tokens, never card numbers: the BFF and Commerce
 * never see a PAN, which keeps both out of the PCI DSS scope of card data.
 */
export const TEST_CARDS: readonly Option[] = [
  { value: 'tok_visa', title: 'Cartão de teste que aprova' },
  { value: 'tok_decline', title: 'Cartão de teste recusado' },
  { value: 'tok_insufficient', title: 'Cartão de teste sem saldo' },
];

export type OrderView = {
  /** The customer just paid: the screen follows the payment until the PSP answers. */
  readonly awaitingPayment: boolean;
  /** The idempotency key of the pay form, when the screen offers one. */
  readonly key: string;
};

export function orderScreen(order: Order, view: OrderView): Entity {
  const confirming = view.awaitingPayment && order.status === 'pending_payment';
  const look = confirming ? CONFIRMING : LOOKS[order.status];
  const notice = noticeOf(order, view.awaitingPayment);
  const self = href(path`/orders/${order.orderId}`, {
    awaiting: confirming ? 'payment' : undefined,
  });

  return screen('order', {
    title: `Pedido ${order.orderNumber}`,
    properties: {
      orderId: order.orderId,
      orderNumber: order.orderNumber,
      status: order.status,
      statusLabel: look.label,
      tone: look.tone,
      placedAt: order.placedAt,
      reservationExpiresAt: order.reservationExpiresAt,
      total: money(order.total.amount, order.total.currency),
      trackingCode: order.trackingCode,
      ...(notice === undefined ? {} : { notice }),
    },
    entities: order.lines.map(orderLine),
    // Paying twice is not a thing: the form leaves while the first payment is on its way.
    actions: order.status === 'pending_payment' && !confirming ? [payAction(order, view.key)] : [],
    links: [{ rel: [rel.self], href: self }, ...trackLink(order), catalogLink()],
    ...(look.refreshAfterSeconds === undefined
      ? {}
      : { refreshAfterSeconds: look.refreshAfterSeconds }),
  });
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
    default:
      // Paid and beyond: when the customer was waiting for the payment, it went through.
      return awaitingPayment ? NOTICES.approved : undefined;
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
