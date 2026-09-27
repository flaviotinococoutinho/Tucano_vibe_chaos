import Fastify, { type FastifyInstance, LogController } from 'fastify';
import type { Config } from './config.ts';
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
};

/** Builds the HTTP app without opening a port: `server.ts` listens, tests call `inject()`. */
export function buildApp({ config, checks = [], logStream }: AppOptions): FastifyInstance {
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

  return app;
}
