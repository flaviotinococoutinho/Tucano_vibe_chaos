import { once } from 'node:events';
import type { FastifyPluginAsync, FastifyReply, FastifyRequest } from 'fastify';
import type { Random } from '../chance.ts';
import type { Clock } from '../clock.ts';
import type { PayFakeConfig } from '../config.ts';
import { Chaos, type ChaosSettings, InjectedFailure } from './chaos.ts';
import type { Charge, Money, Refund } from './charge.ts';
import { IdempotencyKeys, idempotencyKeyOf } from './idempotency.ts';
import { type ChargeRequest, PayFake, RETENTION } from './payfake.ts';
import {
  chaosSettingsSchema,
  chargeLookupSchema,
  chargeRequestSchema,
  refundRequestSchema,
} from './schemas.ts';
import { type Origin, Webhooks } from './webhooks.ts';

/** The longest the timeout rate holds an answer, for a client that never gives up. */
const HOLD_LIMIT_MS = 30_000;

export type PayFakeRoutesOptions = {
  readonly config: PayFakeConfig;
  readonly clock: Clock;
  readonly random: Random;
};

type ChargeRoute = { Params: { id: string } };

/**
 * PayFake over HTTP: the PSP API under /payfake/v1 and its chaos controls under
 * /_chaos/payfake. Charges, keys and knobs live in the memory of this plugin.
 */
export const payfakeRoutes: FastifyPluginAsync<PayFakeRoutesOptions> = async (
  app,
  { config, clock, random },
) => {
  const shutdown = new AbortController();
  const chaos = new Chaos(random);
  const webhooks = new Webhooks({ target: config.webhook, clock, chaos, signal: shutdown.signal });
  const payfake = new PayFake({
    clock,
    random,
    chaos,
    webhooks,
    processingDelayMs: config.processingDelayMs,
    signal: shutdown.signal,
  });
  const chargeKeys = new IdempotencyKeys<Charge>(clock, RETENTION);
  const refundKeys = new IdempotencyKeys<Refund>(clock, RETENTION);

  // preClose runs before the server waits for the requests in flight, which a held answer
  // or a pending settlement would otherwise keep open.
  app.addHook('preClose', (done) => {
    shutdown.abort();
    done();
  });

  // Fastify closes only idle connections, so a connection that answers after the shutdown
  // began would stay open until its keep-alive timeout, and the server with it.
  app.addHook('onSend', async (_request, reply) => {
    if (shutdown.signal.aborted) {
      reply.header('connection', 'close');
    }
  });

  /** A shutdown cuts the pause short, and the request still gets its answer. */
  const pause = (ms: number): Promise<void> =>
    clock.sleep(ms, shutdown.signal).catch(() => undefined);

  const latency = async (request: FastifyRequest, chargeId: string): Promise<void> => {
    const delayMs = chaos.latency(request.log, chargeId);
    if (delayMs > 0) {
      await pause(delayMs);
    }
  };

  /** The answer is ready and the charge exists: only the client does not know it yet. */
  const holdUntilClientGivesUp = async (
    request: FastifyRequest,
    reply: FastifyReply,
    chargeId: string,
  ): Promise<void> => {
    const startedAt = clock.now();
    if (!reply.raw.destroyed) {
      const released = new AbortController();
      const signal = AbortSignal.any([shutdown.signal, released.signal]);
      await Promise.race([once(reply.raw, 'close', { signal }), clock.sleep(HOLD_LIMIT_MS, signal)])
        .catch(() => undefined)
        .finally(() => released.abort());
    }
    const heldMs = clock.now() - startedAt;
    const outcome = reply.raw.destroyed ? 'client gave up waiting' : 'held response released';
    request.log.info({ chargeId, heldMs }, outcome);
  };

  app.post<{ Body: ChargeRequest }>(
    '/payfake/v1/charges',
    { schema: { body: chargeRequestSchema }, onRequest: requireIdempotencyKey },
    async (request, reply) => {
      const { body } = request;
      // Only a real creation can fail: a replay answers what the first request got.
      const { response: charge, replayed } = chargeKeys.remember(
        idempotencyKeyOf(request),
        body,
        () => {
          if (chaos.failsCharge(request.log, body.reference)) {
            throw new InjectedFailure();
          }
          return payfake.createCharge(body, originOf(request));
        },
      );
      await latency(request, charge.id);
      if (chaos.holdsResponse(request.log, charge.id)) {
        await holdUntilClientGivesUp(request, reply, charge.id);
      }

      return created(reply, charge, replayed);
    },
  );

  // Like a real PSP's search by metadata: reconciliation finds a charge even when the
  // answer that carried its id was lost to a timeout.
  app.get<{ Querystring: { reference: string } }>(
    '/payfake/v1/charges',
    { schema: { querystring: chargeLookupSchema } },
    async (request) => {
      const charge = payfake.chargeFor(request.query.reference);

      return { data: charge === undefined ? [] : [charge] };
    },
  );

  app.get<ChargeRoute>('/payfake/v1/charges/:id', async (request) => {
    const charge = payfake.charge(request.params.id);
    await latency(request, charge.id);

    return charge;
  });

  app.post<ChargeRoute & { Body: { amount: Money } }>(
    '/payfake/v1/charges/:id/refunds',
    { schema: { body: refundRequestSchema }, onRequest: requireIdempotencyKey },
    async (request, reply) => {
      const chargeId = request.params.id;
      const { amount } = request.body;
      // The charge id is part of the request: the same key on another charge is a conflict.
      const { response: refund, replayed } = refundKeys.remember(
        idempotencyKeyOf(request),
        { chargeId, amount },
        () => payfake.refund(chargeId, amount, originOf(request)),
      );
      await latency(request, chargeId);

      return created(reply, refund, replayed);
    },
  );

  app.get('/_chaos/payfake', async () => chaos.settings);

  app.put<{ Body: ChaosSettings }>(
    '/_chaos/payfake',
    { schema: { body: chaosSettingsSchema } },
    async (request) => {
      chaos.change(request.body);
      request.log.info({ settings: chaos.settings }, 'chaos settings changed');

      return chaos.settings;
    },
  );

  app.delete('/_chaos/payfake', async (request) => {
    chaos.reset();
    request.log.info({ settings: chaos.settings }, 'chaos settings back to calm');

    return chaos.settings;
  });
};

/** Checked before the body, so a request without a key gets 400 even if its body is wrong too. */
async function requireIdempotencyKey(request: FastifyRequest): Promise<void> {
  idempotencyKeyOf(request);
}

function originOf(request: FastifyRequest): Origin {
  return { log: request.log, correlationId: request.id };
}

function created(reply: FastifyReply, body: Charge | Refund, replayed: boolean): FastifyReply {
  if (replayed) {
    reply.header('Idempotent-Replayed', 'true');
  }

  return reply.code(201).send(body);
}
