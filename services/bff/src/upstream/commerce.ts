import type { Fields, Price } from './fields.ts';
import { call, type Trace, type Upstream } from './http.ts';

/** Commerce takes 1 to 10 units of an item in an order (Quantity). */
export const MAX_UNITS_PER_ITEM = 10;

const ORDER_STATUSES = [
  'pending_payment',
  'paid',
  'shipped',
  'delivered',
  'cancelled',
  'returned',
] as const;

export type OrderStatus = (typeof ORDER_STATUSES)[number];

const CANCELLATION_REASONS = [
  'payment_declined',
  'reservation_expired',
  'customer_request',
] as const;

export type CancellationReason = (typeof CANCELLATION_REASONS)[number];

export type OrderLine = {
  readonly sku: string;
  readonly name: string;
  readonly quantity: number;
  readonly unitPrice: Price;
  readonly subtotal: Price;
};

export type Order = {
  readonly orderId: string;
  /** A Snowflake in decimal text, like a tweet id: shown as it comes, never parsed. */
  readonly orderNumber: string;
  readonly status: OrderStatus;
  readonly lines: readonly OrderLine[];
  readonly total: Price;
  readonly placedAt: string;
  readonly reservationExpiresAt: string;
  /** Known once the carrier picks the parcels up (UC-ORD-04). */
  readonly trackingCode: string | null;
  /** Why a cancelled order stopped; null for every other status. */
  readonly cancellationReason: CancellationReason | null;
};

/** One move of the order state machine, from Commerce's append-only history. */
export type OrderTransition = {
  readonly status: OrderStatus;
  readonly at: string;
  /** Why the order moved, when the move has a reason: payment_declined, for one. */
  readonly reason: string | null;
};

/** An order as its customer reads it: the order and every status it went through, oldest first. */
export type CustomerOrder = {
  readonly order: Order;
  readonly history: readonly OrderTransition[];
};

/** An order in the list of its customer, from the read model: what a card shows, no more. */
export type OrderSummary = {
  readonly orderId: string;
  readonly orderNumber: string;
  readonly status: OrderStatus;
  readonly cancellationReason: CancellationReason | null;
  readonly total: Price;
  readonly lines: readonly {
    readonly sku: string;
    readonly name: string;
    readonly quantity: number;
  }[];
  readonly placedAt: string;
  /** When the read model last heard of the order: the moment it reached its status. */
  readonly updatedAt: string;
};

/** A page of the orders of one customer, newest first. */
export type CustomerOrderPage = {
  readonly orders: readonly OrderSummary[];
  readonly page: number;
  readonly perPage: number;
  readonly total: number;
};

/** A territorial division of the address, from the state down (ADR 0020). */
export type Division = {
  readonly kind: 'state' | 'municipality' | 'neighborhood';
  readonly code: string | null;
  readonly name: string;
};

/** The order Commerce places, in the shape of its API. */
export type NewOrder = {
  readonly customer: { readonly id: string; readonly name: string; readonly email: string };
  readonly shippingAddress: {
    readonly thoroughfare: { readonly type: string; readonly name: string };
    readonly number: string;
    readonly complement: string | null;
    readonly divisions: readonly Division[];
    readonly postalCode: string;
  };
  readonly items: readonly { readonly sku: string; readonly quantity: number }[];
};

/**
 * Commerce said no: its status, the name of the problem when it has one
 * (contracts/http/problems.md), and the fields it named when it named any (`customer.email`).
 */
export type Refusal = {
  readonly outcome: 'refused';
  readonly status: 409 | 422;
  readonly problem: string | null;
  readonly fields: readonly string[];
};

export type Placement = { readonly outcome: 'placed'; readonly order: Order } | Refusal;

export type PaymentRequest =
  | { readonly outcome: 'accepted' }
  | { readonly outcome: 'unknown_order' }
  | Refusal;

/**
 * The commerce service (Laravel): orders and their payments, in the words the BFF uses.
 * Every read goes through the routes of one customer, so an order of somebody else reads
 * exactly like an order that does not exist.
 */
export type Commerce = {
  /** The order of that customer, with its history; null when the customer has no such order. */
  customerOrder(customerId: string, orderId: string, trace: Trace): Promise<CustomerOrder | null>;
  /** A page of the orders of that customer, newest first, from a read model a few seconds behind. */
  customerOrders(
    customerId: string,
    page: number,
    perPage: number,
    trace: Trace,
  ): Promise<CustomerOrderPage>;
  /** Places the order once per key: the same key and body again get the same order back. */
  placeOrder(key: string, order: NewOrder, trace: Trace): Promise<Placement>;
  /** Sends the charge; the outcome arrives later, and the order tells it. */
  pay(orderId: string, key: string, cardToken: string, trace: Trace): Promise<PaymentRequest>;
};

export function commerceAt(upstream: Upstream): Commerce {
  return {
    async customerOrder(customerId, orderId, trace) {
      const path = `${customerPath(customerId)}/orders/${encodeURIComponent(orderId)}`;
      const answer = await call(upstream, { method: 'GET', path }, trace);
      if (answer.status === 404) {
        return null;
      }
      if (answer.status !== 200) {
        throw answer.unexpected();
      }
      const fields = answer.fields();

      return {
        order: orderOf(fields),
        history: fields.objects('history').map((transition) => ({
          status: transition.oneOf('status', ORDER_STATUSES),
          at: transition.instant('at'),
          reason: transition.optionalText('reason'),
        })),
      };
    },

    async customerOrders(customerId, page, perPage, trace) {
      const query = new URLSearchParams({ page: String(page), perPage: String(perPage) });
      const path = `${customerPath(customerId)}/orders?${query}`;
      const answer = await call(upstream, { method: 'GET', path }, trace);
      if (answer.status !== 200) {
        throw answer.unexpected();
      }
      const fields = answer.fields();

      return {
        orders: fields.objects('orders').map(summaryOf),
        page: fields.integer('page'),
        perPage: fields.integer('perPage'),
        total: fields.integer('total'),
      };
    },

    async placeOrder(key, order, trace) {
      const answer = await call(
        upstream,
        { method: 'POST', path: '/v1/orders', body: order, idempotencyKey: key },
        trace,
      );
      // 201 the first time; a replay of the same key answers the same order.
      if (answer.status === 201 || answer.status === 200) {
        return { outcome: 'placed', order: orderOf(answer.fields()) };
      }
      if (answer.status === 409 || answer.status === 422) {
        return {
          outcome: 'refused',
          status: answer.status,
          problem: answer.problem(),
          fields: answer.fieldsInError(),
        };
      }
      throw answer.unexpected();
    },

    async pay(orderId, key, cardToken, trace) {
      const path = `/v1/orders/${encodeURIComponent(orderId)}/payments`;
      const answer = await call(
        upstream,
        { method: 'POST', path, body: { cardToken }, idempotencyKey: key },
        trace,
      );
      if (answer.status === 202) {
        return { outcome: 'accepted' };
      }
      if (answer.status === 404) {
        return { outcome: 'unknown_order' };
      }
      if (answer.status === 409 || answer.status === 422) {
        return {
          outcome: 'refused',
          status: answer.status,
          problem: answer.problem(),
          fields: answer.fieldsInError(),
        };
      }
      throw answer.unexpected();
    },
  };
}

function orderOf(fields: Fields): Order {
  return {
    orderId: fields.text('orderId'),
    orderNumber: fields.text('orderNumber'),
    status: fields.oneOf('status', ORDER_STATUSES),
    lines: fields.objects('lines').map((line) => ({
      sku: line.text('sku'),
      name: line.text('name'),
      quantity: line.integer('quantity'),
      unitPrice: line.price('unitPrice'),
      subtotal: line.price('subtotal'),
    })),
    total: fields.price('total'),
    placedAt: fields.instant('placedAt'),
    reservationExpiresAt: fields.instant('reservationExpiresAt'),
    trackingCode: fields.optionalText('trackingCode'),
    cancellationReason: fields.optionalOneOf('cancellationReason', CANCELLATION_REASONS),
  };
}

/** Only the BFF reaches these routes: Kong closes them at the edge (ADR 0030). */
function customerPath(customerId: string): string {
  return `/v1/customers/${encodeURIComponent(customerId)}`;
}

function summaryOf(fields: Fields): OrderSummary {
  return {
    orderId: fields.text('orderId'),
    orderNumber: fields.text('orderNumber'),
    status: fields.oneOf('status', ORDER_STATUSES),
    cancellationReason: fields.optionalOneOf('cancellationReason', CANCELLATION_REASONS),
    total: fields.price('total'),
    lines: fields.objects('lines').map((line) => ({
      sku: line.text('sku'),
      name: line.text('name'),
      quantity: line.integer('quantity'),
    })),
    placedAt: fields.instant('placedAt'),
    updatedAt: fields.instant('updatedAt'),
  };
}
