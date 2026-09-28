import type { FastifyPluginAsync } from 'fastify';
import {
  FormReader,
  href,
  InvalidForm,
  type KeyMaker,
  path,
  sendScreen,
} from '../hypermedia/index.ts';
import { DomainError } from '../platform/domain-error.ts';
import {
  type Commerce,
  inSeconds,
  type Order,
  type PaymentRequest,
  ServiceUnavailable,
  type Trace,
  traceOf,
} from '../upstream/index.ts';
import { orderScreen, TEST_CARDS } from './order-screen.ts';

export type OrdersOptions = {
  readonly commerce: Commerce;
  readonly newId: KeyMaker;
};

/** Order ids and idempotency keys are UUIDs; anything else is an order that does not exist. */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const CARD_MESSAGE = 'Escolha um dos cartões de teste.';

export class OrderNotFound extends DomainError {
  readonly category = 'not_found';

  constructor() {
    super('Não encontrei esse pedido.');
  }
}

export class OrderNotPayable extends DomainError {
  readonly category = 'conflict';

  constructor() {
    super('Este pedido não espera mais pagamento. Abra o pedido de novo para ver como ele está.');
  }
}

export const ordersRoutes: FastifyPluginAsync<OrdersOptions> = async (app, { commerce, newId }) => {
  app.get<{ Params: { orderId: string }; Querystring: { awaiting?: string } }>(
    '/v1/orders/:orderId',
    async (request, reply) => {
      const order = await orderOf(commerce, request.params.orderId, traceOf(request));
      const awaitingPayment = request.query.awaiting === 'payment';

      return sendScreen(reply, orderScreen(order, { awaitingPayment, key: newId() }));
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

      const trace = traceOf(request);
      const payment = await asPayment(commerce.pay(orderId, key, cardToken, trace));
      if (payment.outcome === 'unknown_order') {
        throw new OrderNotFound();
      }
      if (payment.outcome === 'refused') {
        throw payment.status === 409
          ? new OrderNotPayable()
          : new InvalidForm({ cardToken: [CARD_MESSAGE] });
      }

      const order = await orderOf(commerce, orderId, trace);
      reply.header('location', href(path`/orders/${orderId}`, { awaiting: 'payment' }));

      return sendScreen(reply, orderScreen(order, { awaitingPayment: true, key: newId() }), 202);
    },
  );
};

async function orderOf(commerce: Commerce, orderId: string, trace: Trace): Promise<Order> {
  const order = UUID.test(orderId) ? await commerce.order(orderId, trace) : null;
  if (order === null) {
    throw new OrderNotFound();
  }
  return order;
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
