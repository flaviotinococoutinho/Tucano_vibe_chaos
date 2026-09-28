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
import { storefrontRoutes } from './storefront/index.ts';
import { trackingRoutes } from './tracking/index.ts';
import { catalogAt, commerceAt, logisticsAt } from './upstream/index.ts';

export type AppOptions = {
  readonly config: Config;
  /** What /health/ready probes; each dependency adds its check here. */
  readonly checks?: readonly HealthCheck[];
  readonly logStream?: LogStream;
  /** Makes idempotency keys and guest ids: UUIDv7, unless a test wants them fixed. */
  readonly newId?: KeyMaker;
};

/** Builds the HTTP app without opening a port: `server.ts` listens, tests call `inject()`. */
export function buildApp({
  config,
  checks = [],
  logStream,
  newId = uuidv7,
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

  app.addHook('onRequest', echoCorrelationId);
  app.setErrorHandler(sendProblem);
  app.setNotFoundHandler(sendNotFound);
  app.register(healthRoutes, { checks });

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

  app.register(storefrontRoutes, { catalog });
  app.register(checkoutRoutes, {
    catalog,
    commerce,
    newId,
    secureCookies: config.environment === 'production',
  });
  app.register(ordersRoutes, { commerce, newId });
  app.register(trackingRoutes, { logistics });

  return app;
}
