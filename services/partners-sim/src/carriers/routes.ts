import type { FastifyPluginAsync, FastifyReply, FastifyRequest } from 'fastify';
import type { Random } from '../chance.ts';
import type { Clock } from '../clock.ts';
import type { CarriersConfig, WebhookDeliveryConfig } from '../config.ts';
import { IdempotencyKeys, idempotencyKeyOf } from '../idempotency.ts';
import { type Origin, Webhooks } from '../webhooks/sender.ts';
import { Carriers, type ParcelEventData, type PickupRequest } from './carriers.ts';
import { Chaos, type ChaosSettings } from './chaos.ts';
import { type Pickup, pickupResponse } from './pickup.ts';
import { chaosSettingsSchema, pickupLookupSchema, pickupRequestSchema } from './schemas.ts';

/** The header every CarrierFake webhook carries. */
const SIGNATURE_HEADER = 'Carrier-Signature';

export type CarriersRoutesOptions = {
  readonly config: CarriersConfig;
  readonly webhookDelivery: WebhookDeliveryConfig;
  readonly clock: Clock;
  readonly random: Random;
};

type PickupRoute = { Params: { pickupId: string } };

/**
 * CarrierFake over HTTP: the carrier API under /carriers/v1 and its chaos controls under
 * /_chaos/carriers. Pickups, keys and knobs live in the memory of this plugin.
 */
export const carriersRoutes: FastifyPluginAsync<CarriersRoutesOptions> = async (
  app,
  { config, webhookDelivery, clock, random },
) => {
  const shutdown = new AbortController();
  const chaos = new Chaos(random);
  const webhooks = new Webhooks<ParcelEventData>({
    target: config.webhook,
    signatureHeader: SIGNATURE_HEADER,
    delivery: webhookDelivery,
    clock,
    plan: (log, event) => chaos.planWebhook(log, event.data.pickupId, event.id),
    signal: shutdown.signal,
  });
  const carriers = new Carriers({
    clock,
    random,
    chaos,
    webhooks,
    stepDelayMs: config.stepDelayMs,
    retention: config.retention,
    signal: shutdown.signal,
  });
  const pickupKeys = new IdempotencyKeys<Pickup>(clock, config.retention);

  // preClose runs before the server waits for the requests in flight; journeys still walking
  // keep the webhook client and the clock's sleeps open otherwise.
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

  app.post<{ Body: PickupRequest }>(
    '/carriers/v1/pickups',
    { schema: { body: pickupRequestSchema }, onRequest: requireIdempotencyKey },
    async (request, reply) => {
      const { body } = request;
      const { response: pickup, replayed } = pickupKeys.remember(
        idempotencyKeyOf(request),
        body,
        () => carriers.bookPickup(body, originOf(request)),
      );

      return created(reply, pickupResponse(pickup), replayed);
    },
  );

  // Like a real carrier's search by metadata: a merchant finds a pickup even when the answer
  // that carried its id was lost to a timeout.
  app.get<{ Querystring: { reference: string } }>(
    '/carriers/v1/pickups',
    { schema: { querystring: pickupLookupSchema } },
    async (request) => {
      const pickup = carriers.pickupFor(request.query.reference);

      return { data: pickup === undefined ? [] : [pickupResponse(pickup)] };
    },
  );

  app.get<PickupRoute>('/carriers/v1/pickups/:pickupId', async (request) =>
    pickupResponse(carriers.pickup(request.params.pickupId)),
  );

  // The tracking history a real carrier offers: the same events its webhooks carry, including
  // the ones whose webhook never arrived, for the merchant to catch up with.
  app.get<PickupRoute>('/carriers/v1/pickups/:pickupId/events', async (request) => ({
    data: carriers.events(request.params.pickupId),
  }));

  app.get('/_chaos/carriers', async () => chaos.settings);

  app.put<{ Body: ChaosSettings }>(
    '/_chaos/carriers',
    { schema: { body: chaosSettingsSchema } },
    async (request) => {
      chaos.change(request.body);
      request.log.info({ settings: chaos.settings }, 'chaos settings changed');

      return chaos.settings;
    },
  );

  app.delete('/_chaos/carriers', async (request) => {
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

function created(
  reply: FastifyReply,
  body: ReturnType<typeof pickupResponse>,
  replayed: boolean,
): FastifyReply {
  if (replayed) {
    reply.header('Idempotent-Replayed', 'true');
  }

  return reply.code(201).send(body);
}
