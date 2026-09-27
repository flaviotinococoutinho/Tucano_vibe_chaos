import type { FastifyPluginAsync, FastifyRequest } from 'fastify';
import { type HealthCheck, probe } from './readiness.ts';

export type HealthRoutesOptions = { readonly checks: readonly HealthCheck[] };

const LIVE = '/health/live';
const READY = '/health/ready';

export const healthRoutes: FastifyPluginAsync<HealthRoutesOptions> = async (app, { checks }) => {
  // Liveness: the process answers. Orchestrators restart the container when this fails.
  app.get(LIVE, async () => ({ status: 'up' }));

  // Readiness: dependencies answer too. Load balancers stop sending traffic when this fails.
  app.get(READY, async (request, reply) => {
    const report = await probe(checks);
    if (report.status === 'down') {
      request.log.warn({ checks: report.checks }, 'not ready');
    }

    return reply.code(report.status === 'up' ? 200 : 503).send(report);
  });
};

/**
 * The compose healthcheck calls these every few seconds, so their request lines would
 * bury real traffic. What they log on purpose, like a dependency going down, still shows.
 */
export function isProbe(request: FastifyRequest): boolean {
  return request.routeOptions.url === LIVE || request.routeOptions.url === READY;
}
