import Fastify, { type FastifyInstance, LogController } from 'fastify';
import { carriersRoutes } from './carriers/routes.ts';
import type { Random } from './chance.ts';
import { type Clock, systemClock } from './clock.ts';
import type { Config } from './config.ts';
import { payfakeRoutes } from './payfake/routes.ts';
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

export type AppOptions = {
  readonly config: Config;
  /** What /health/ready probes; each dependency adds its check here. */
  readonly checks?: readonly HealthCheck[];
  readonly logStream?: LogStream;
  /** Time behind every simulated delay. Tests pass one they control. */
  readonly clock?: Clock;
  /** Chance behind every chaos decision and processing delay. Tests pass a fixed one. */
  readonly random?: Random;
};

/** Builds the HTTP app without opening a port: `server.ts` listens, tests call `inject()`. */
export function buildApp({
  config,
  checks = [],
  logStream,
  clock = systemClock,
  random = Math.random,
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
    // Partners are strict: an unknown field or a wrong type is refused, never dropped or
    // coerced. $data lets a schema compare two fields of the same body.
    ajv: { customOptions: { removeAdditional: false, coerceTypes: false, $data: true } },
  });

  app.addHook('onRequest', echoCorrelationId);
  app.setErrorHandler(sendProblem);
  app.setNotFoundHandler(sendNotFound);
  app.register(healthRoutes, { checks });
  app.register(payfakeRoutes, {
    config: config.payfake,
    webhookDelivery: config.webhooks,
    clock,
    random,
  });
  app.register(carriersRoutes, {
    config: config.carriers,
    webhookDelivery: config.webhooks,
    clock,
    random,
  });

  return app;
}
