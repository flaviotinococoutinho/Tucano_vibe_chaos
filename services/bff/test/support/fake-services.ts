import type { IncomingHttpHeaders } from 'node:http';
import Fastify, { type FastifyInstance } from 'fastify';

export type Received = {
  readonly method: string;
  readonly url: string;
  readonly headers: IncomingHttpHeaders;
  readonly body: unknown;
};

export type FakeServices = {
  readonly url: string;
  /** Every request the fake got, oldest first. */
  readonly received: Received[];
  close(): Promise<void>;
};

/**
 * One HTTP server that plays catalog, commerce and logistics at once: their paths never
 * clash, so the three base URLs of the BFF can point to it. Each test declares only the
 * routes it needs, with the status and the body the real service would send.
 */
export async function fakeServices(routes: (app: FastifyInstance) => void): Promise<FakeServices> {
  const app = Fastify();
  const received: Received[] = [];
  app.addHook('preHandler', async (request) => {
    received.push({
      method: request.method,
      url: request.url,
      headers: request.headers,
      body: request.body,
    });
  });
  routes(app);
  const url = await app.listen({ host: '127.0.0.1', port: 0 });

  return { url, received, close: () => app.close() };
}

/** Fixed ids in the order they are asked for, so a test knows every key and guest id. */
export function ids(...values: string[]): () => string {
  let next = 0;
  return () => {
    const value = values[next % values.length];
    next += 1;
    if (value === undefined) {
      throw new Error('ids() needs at least one value');
    }
    return value;
  };
}
