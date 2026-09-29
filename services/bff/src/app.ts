import Fastify, { type FastifyInstance, LogController } from 'fastify';
import { v7 as uuidv7 } from 'uuid';
import { checkoutRoutes } from './checkout/index.ts';
import type { Config } from './config.ts';
import type { KeyMaker } from './hypermedia/index.ts';
import { ordersRoutes } from './orders/index.ts';
import {
  CORRELATION_ID_HEADER,
  CORRELATION_ID_LOG_FIELD,
  echoCorrelationId,
  newCorrelationId,
} from './platform/correlation-id.ts';
import { healthRoutes, isProbe } from './platform/health-routes.ts';
import { type LogStream, logOptions } from './platform/logging.ts';
import { sendNotFound, sendProblem } from './platform/problem-details.ts';
import type { HealthCheck } from './platform/readiness.ts';
import { navigationFor, profilesRoutes } from './profiles/index.ts';
import { sessionsWith } from './session/index.ts';
import { homeRoutes, storefrontRoutes } from './storefront/index.ts';
import { storesFrom } from './stores/index.ts';
import { lookupRoutes, trackingRoutes } from './tracking/index.ts';
import { catalogAt, commerceAt, logisticsAt } from './upstream/index.ts';

export type AppOptions = {
  readonly config: Config;
  /** What /health/ready probes; each dependency adds its check here. */
  readonly checks?: readonly HealthCheck[];
  readonly logStream?: LogStream;
  /** Makes idempotency keys and profile ids: UUIDv7, unless a test wants them fixed. */
  readonly newId?: KeyMaker;
  /** The clock of the stores kept in memory, in milliseconds, which a test moves by hand. */
  readonly clock?: () => number;
};

/** Builds the HTTP app without opening a port: `server.ts` listens, tests call `inject()`. */
export function buildApp({
  config,
  checks = [],
  logStream,
  newId = uuidv7,
  clock,
}: AppOptions): FastifyInstance {
  const app = Fastify({
    logger: logOptions(config, logStream),
    logController: new LogController({
      requestIdLogLabel: CORRELATION_ID_LOG_FIELD,
      disableRequestLogging: isProbe,
    }),
    requestIdHeader: CORRELATION_ID_HEADER,
    genReqId: newCorrelationId,
    // Malformed URLs fail before routing, so they skip the hooks and the error handler.
    frameworkErrors: (error, request, reply) => {
      reply.header(CORRELATION_ID_HEADER, request.id);
      sendProblem(error, request, reply);
    },
  });

  // The session lives in a signed cookie: every screen leaves with the navigation of the
  // session as the request ends, and the cookie goes out once, whatever the answer is.
  const sessions = sessionsWith({
    secret: config.sessionSecret,
    secure: config.environment === 'production',
  });

  // Readiness does not ask the services behind: with Commerce out, the catalog still
  // opens. Each screen answers 503 only when the service it needs is out.
  const timeoutMs = config.upstreamTimeoutMs;
  const catalog = catalogAt({
    name: 'catalog',
    called: 'o catálogo',
    baseUrl: config.upstreams.catalog,
    timeoutMs,
  });
  const commerce = commerceAt({
    name: 'commerce',
    called: 'o serviço de pedidos',
    baseUrl: config.upstreams.commerce,
    timeoutMs,
  });
  const logistics = logisticsAt({
    name: 'logistics',
    called: 'o rastreio',
    baseUrl: config.upstreams.logistics,
    timeoutMs,
  });
  // The same logistics, asked with the deadline of an enrichment: the order waits for its
  // delivery news this long at most, and never longer than for anything else.
  const deliveryNews = logisticsAt({
    name: 'logistics',
    called: 'o rastreio',
    baseUrl: config.upstreams.logistics,
    timeoutMs: Math.min(config.enrichmentTimeoutMs, timeoutMs),
  });

  // Every store screen needs its store, and the catalog keeps them: the BFF keeps them too,
  // for a minute, so a catalog out holds only the screens that show products.
  const stores = storesFrom({ catalog, ...(clock === undefined ? {} : { now: clock }) });

  app.addHook('onRequest', echoCorrelationId);
  app.addHook('onRequest', sessions.forgetGuest);
  app.addHook('preSerialization', navigationFor(sessions, stores));
  app.addHook('onSend', sessions.writeCookie);
  app.setErrorHandler(sendProblem);
  app.setNotFoundHandler(sendNotFound);
  app.register(healthRoutes, { checks });

  // The platform: the stores it hosts, the tracking of any parcel, and the profiles, which
  // belong to the platform and shop in every store.
  app.register(homeRoutes, { stores });
  app.register(lookupRoutes, { logistics });
  app.register(profilesRoutes, { sessions, stores, newId });

  // The stores: every route under /v1/stores/:store enters its store before anything else,
  // so an unknown store is a 404 on every one of them, and no route of a store can forget it.
  const livePath = config.trackingLivePath;
  app.register(
    async (store) => {
      store.addHook('onRequest', stores.enter);
      store.register(storefrontRoutes, { catalog, stores });
      store.register(checkoutRoutes, { catalog, commerce, sessions, stores, newId });
      store.register(ordersRoutes, { commerce, deliveryNews, sessions, stores, newId, livePath });
      store.register(trackingRoutes, { logistics, stores, livePath });
    },
    { prefix: '/v1/stores/:store' },
  );

  return app;
}
