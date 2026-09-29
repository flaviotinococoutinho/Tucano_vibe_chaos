import type { FastifyPluginAsync, FastifyRequest } from 'fastify';
import {
  FormReader,
  href,
  InvalidForm,
  type KeyMaker,
  PAGE_QUERY,
  path,
  sendScreen,
} from '../hypermedia/index.ts';
import { DomainError } from '../platform/domain-error.ts';
import { activeProfile, type Sessions } from '../session/index.ts';
import {
  type Commerce,
  type CustomerOrderPage,
  inSeconds,
  type Logistics,
  type Order,
  type PaymentRequest,
  type Refusal,
  ServiceUnavailable,
  type Trace,
  traceOf,
  UpstreamContractBroken,
} from '../upstream/index.ts';
import { type OrderStory, orderScreen, TEST_CARDS } from './order-screen.ts';
import { noOrders, ORDERS_PER_PAGE, ordersScreen } from './orders-screen.ts';
import type { Delivery } from './story.ts';

export type OrdersOptions = {
  readonly commerce: Commerce;
  /** Logistics with the short deadline of an enrichment: its news adds to the order, never holds it. */
  readonly deliveryNews: Logistics;
  readonly sessions: Sessions;
  readonly newId: KeyMaker;
  /** Where the live link of the order points to: a path on the public origin, not a BFF route. */
  readonly livePath: string;
};

/** Order ids and idempotency keys are UUIDs; anything else is an order that does not exist. */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const CARD_MESSAGE = 'Escolha um dos cartões de teste.';

/**
 * The same answer for an order that does not exist and for an order of another profile, so
 * the answer never tells one from the other; the second sentence helps whoever switched.
 */
export class OrderNotFound extends DomainError {
  readonly category = 'not_found';

  constructor() {
    super(
      'Não encontrei esse pedido. Se ele foi feito com outro perfil, troque de perfil e abra de novo.',
    );
  }
}

export class OrderNotPayable extends DomainError {
  readonly category = 'conflict';

  constructor() {
    super('Este pedido não espera mais pagamento. Abra o pedido de novo para ver como ele está.');
  }
}

/** The same pay form went again with another card after the first payment left. */
export class PaymentAlreadySent extends DomainError {
  readonly category = 'invalid_input';

  constructor() {
    super(
      'Este pagamento já foi enviado com outro cartão. Abra o pedido de novo para ver como ele está.',
    );
  }
}

export const ordersRoutes: FastifyPluginAsync<OrdersOptions> = async (app, options) => {
  const { commerce, sessions, newId } = options;

  /** The id of the profile shopping now, or a not found: without a session, no order is anybody's. */
  const shopperOf = (request: FastifyRequest): string => {
    const session = sessions.of(request);
    if (session === null) {
      throw new OrderNotFound();
    }
    return activeProfile(session).id;
  };

  // Only a session that exists is asked about: a plain read never starts one.
  app.get<{ Querystring: { page?: number } }>(
    '/v1/orders',
    { schema: { querystring: PAGE_QUERY } },
    async (request, reply) => {
      const page = request.query.page ?? 1;
      const session = sessions.of(request);
      const orders =
        session === null
          ? noOrders(page)
          : await listOf(commerce, activeProfile(session).id, page, traceOf(request));

      return sendScreen(reply, ordersScreen(orders));
    },
  );

  app.get<{ Params: { orderId: string }; Querystring: { awaiting?: string } }>(
    '/v1/orders/:orderId',
    async (request, reply) => {
      const story = await storyOf(options, shopperOf(request), request.params.orderId, request);
      const awaitingPayment = request.query.awaiting === 'payment';

      return sendScreen(reply, orderScreen(story, { awaitingPayment, key: newId() }));
    },
  );

  // 202 Accepted: the charge went to the PSP, and the outcome arrives by webhook. The
  // answer is the order following its payment, and Location is where it lives.
  app.post<{ Params: { orderId: string } }>(
    '/v1/orders/:orderId/payments',
    async (request, reply) => {
      const { orderId } = request.params;
      if (!UUID.test(orderId)) {
        throw new OrderNotFound();
      }
      const form = new FormReader(request.body);
      const key = form.text('idempotencyKey', {
        maxlength: 36,
        pattern: UUID,
        message: 'Abra o pedido de novo e tente outra vez.',
      });
      const cardToken = form.choice(
        'cardToken',
        TEST_CARDS.map((card) => card.value),
        CARD_MESSAGE,
      );
      form.done();

      // Commerce takes a payment for any order id, so the BFF asks first whose order it is.
      const trace = traceOf(request);
      const shopper = shopperOf(request);
      if ((await commerce.customerOrder(shopper, orderId, trace)) === null) {
        throw new OrderNotFound();
      }
      const payment = await asPayment(commerce.pay(orderId, key, cardToken, trace));
      if (payment.outcome === 'unknown_order') {
        throw new OrderNotFound();
      }
      if (payment.outcome === 'refused') {
        throw refusalOf(payment);
      }

      const story = await storyOf(options, shopper, orderId, request);
      reply.header('location', href(path`/orders/${orderId}`, { awaiting: 'payment' }));

      return sendScreen(reply, orderScreen(story, { awaitingPayment: true, key: newId() }), 202);
    },
  );
};

/** The type of the problem first: a status alone does not tell a reused form from a bad card. */
function refusalOf(refusal: Refusal): DomainError {
  if (refusal.problem === 'idempotency-key-reused') {
    return new PaymentAlreadySent();
  }
  return refusal.status === 409
    ? new OrderNotPayable()
    : new InvalidForm({ cardToken: [CARD_MESSAGE] });
}

/** The order of the shopper, with its history and the news of its parcel. */
async function storyOf(
  { commerce, deliveryNews, livePath }: OrdersOptions,
  shopper: string,
  orderId: string,
  request: FastifyRequest,
): Promise<OrderStory> {
  const trace = traceOf(request);
  const found = UUID.test(orderId) ? await commerce.customerOrder(shopper, orderId, trace) : null;
  if (found === null) {
    throw new OrderNotFound();
  }

  return {
    order: found.order,
    history: found.history,
    delivery: await deliveryOf(found.order, deliveryNews, livePath, trace),
  };
}

/**
 * The news of the parcel is an enrichment: logistics gets a deadline of its own, and without
 * an answer the order still opens, with what Commerce knows and a notice. A broken contract
 * leaves the news out too, with an error line for a person to read: an order must not become
 * unreachable because what only decorates it broke.
 */
async function deliveryOf(
  order: Order,
  logistics: Logistics,
  livePath: string,
  trace: Trace,
): Promise<Delivery> {
  if (order.trackingCode === null) {
    return { news: 'none' };
  }
  try {
    const tracking = await logistics.tracking(order.trackingCode, trace);
    return tracking === null ? { news: 'none' } : { news: 'known', tracking, livePath };
  } catch (error) {
    // A timeout or an outage already left its warn line, with the call and the time it took.
    if (error instanceof ServiceUnavailable) {
      return { news: 'unavailable' };
    }
    if (error instanceof UpstreamContractBroken) {
      trace.log.error({ err: error }, 'left the delivery news out of the order');
      return { news: 'unavailable' };
    }
    throw error;
  }
}

/** Only the list reads the read model; with it out, the list answers 503 and the orders still open. */
async function listOf(
  commerce: Commerce,
  customerId: string,
  page: number,
  trace: Trace,
): Promise<CustomerOrderPage> {
  try {
    return await commerce.customerOrders(customerId, page, ORDERS_PER_PAGE, trace);
  } catch (error) {
    if (!(error instanceof ServiceUnavailable)) {
      throw error;
    }
    const seconds = error.retryAfterSeconds ?? 5;
    throw new ServiceUnavailable(
      `A lista de pedidos está fora do ar agora. Tente de novo ${inSeconds(seconds)}.`,
      seconds,
    );
  }
}

/**
 * Whether the BFF could not reach Commerce or Commerce could not reach the PSP (its
 * circuit breaker is open), the customer hears the same thing: paying is out for now.
 */
async function asPayment(request: Promise<PaymentRequest>): Promise<PaymentRequest> {
  try {
    return await request;
  } catch (error) {
    if (!(error instanceof ServiceUnavailable)) {
      throw error;
    }
    const seconds = error.retryAfterSeconds ?? 5;
    throw new ServiceUnavailable(
      `O pagamento está fora do ar agora. Tente de novo ${inSeconds(seconds)}.`,
      seconds,
    );
  }
}
