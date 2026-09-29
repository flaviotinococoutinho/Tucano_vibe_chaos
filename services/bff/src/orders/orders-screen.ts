import {
  component,
  type Entity,
  href,
  money,
  type Notice,
  pageLinks,
  path,
  rel,
  screen,
} from '../hypermedia/index.ts';
import type { CustomerOrderPage, OrderSummary, OrderTransition } from '../upstream/index.ts';
import { lookOf } from './looks.ts';
import { progressOf } from './story.ts';

/** How many orders a page of the list shows: a choice of the screen, not a setting. */
export const ORDERS_PER_PAGE = 10;

/** The list reads a read model a few seconds behind the orders (ADR 0012), and says so. */
const FRESHNESS: Notice = {
  tone: 'info',
  text: 'Um pedido novo pode levar alguns segundos para aparecer aqui.',
};

export function ordersScreen(page: CustomerOrderPage): Entity {
  const at = (number: number): string => href('/orders', { page: number });

  return screen('orders', {
    title: 'Meus pedidos',
    properties: { page: page.page, perPage: page.perPage, total: page.total, notice: FRESHNESS },
    entities: page.orders.map(orderSummary),
    links: [
      ...pageLinks(page, at),
      { rel: [rel.catalog], href: href('/products'), title: 'Ver o catálogo' },
    ],
  });
}

/** The list of a browser with no session: nobody bought anything yet, so nobody is asked. */
export function noOrders(page: number): CustomerOrderPage {
  return { orders: [], page, perPage: ORDERS_PER_PAGE, total: 0 };
}

function orderSummary(summary: OrderSummary): Entity {
  const look = lookOf(summary.status);

  return component('order-summary', {
    rel: [rel.item],
    title: `Pedido ${summary.orderNumber}`,
    properties: {
      orderId: summary.orderId,
      orderNumber: summary.orderNumber,
      status: summary.status,
      statusLabel: look.label,
      tone: look.tone,
      placedAt: summary.placedAt,
      total: money(summary.total.amount, summary.total.currency),
      itemsLabel: itemsLabelOf(summary.lines),
      progress: progressOf({
        status: summary.status,
        placedAt: summary.placedAt,
        cancellationReason: summary.cancellationReason,
        transitions: transitionsOf(summary),
      }),
    },
    links: [{ rel: [rel.self], href: href(path`/orders/${summary.orderId}`) }],
  });
}

/**
 * The list knows two moments of an order: when it was placed, and when it reached the
 * status it is in, which is when the read model last heard of it.
 */
function transitionsOf(summary: OrderSummary): OrderTransition[] {
  const placed: OrderTransition = {
    status: 'pending_payment',
    at: summary.placedAt,
    reason: null,
  };
  if (summary.status === 'pending_payment') {
    return [placed];
  }

  return [
    placed,
    { status: summary.status, at: summary.updatedAt, reason: summary.cancellationReason },
  ];
}

/**
 * What was bought, in a line: "Domain-Driven Design", "2x Caneca de cerâmica" or
 * "Domain-Driven Design e mais 1 item". The count is the other lines, not the units: a
 * product name cannot be put in the plural by a rule, so a quantity reads as "2x".
 */
function itemsLabelOf(lines: OrderSummary['lines']): string {
  const [first, ...others] = lines;
  if (first === undefined) {
    return '';
  }
  const name = first.quantity > 1 ? `${first.quantity}x ${first.name}` : first.name;
  if (others.length === 0) {
    return name;
  }

  return `${name} e mais ${others.length} ${others.length === 1 ? 'item' : 'itens'}`;
}
