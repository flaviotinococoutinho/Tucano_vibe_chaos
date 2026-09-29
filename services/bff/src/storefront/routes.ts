import type { FastifyPluginAsync } from 'fastify';
import { PAGE_QUERY, sendScreen } from '../hypermedia/index.ts';
import { DomainError } from '../platform/domain-error.ts';
import { type Catalog, traceOf } from '../upstream/index.ts';
import { catalogScreen, homeScreen, productScreen } from './screens.ts';

export type StorefrontOptions = { readonly catalog: Catalog };

/** The shape of a SKU the catalog routes accept; anything else is a product that does not exist. */
export const SKU = /^[A-Z0-9][A-Z0-9-]{2,31}$/;

export class ProductNotFound extends DomainError {
  readonly category = 'not_found';

  constructor(sku: string) {
    super(`Não encontrei o produto ${sku}.`);
  }
}

export const storefrontRoutes: FastifyPluginAsync<StorefrontOptions> = async (app, { catalog }) => {
  // The entry point: the one address the web knows by heart.
  app.get('/v1', async (_request, reply) => sendScreen(reply, homeScreen()));

  app.get<{ Querystring: { page?: number } }>(
    '/v1/products',
    { schema: { querystring: PAGE_QUERY } },
    async (request, reply) => {
      const page = await catalog.page(request.query.page ?? 1, traceOf(request));
      return sendScreen(reply, catalogScreen(page));
    },
  );

  app.get<{ Params: { sku: string } }>('/v1/products/:sku', async (request, reply) => {
    const { sku } = request.params;
    const product = SKU.test(sku) ? await catalog.product(sku, traceOf(request)) : null;
    if (product === null) {
      throw new ProductNotFound(sku);
    }

    return sendScreen(reply, productScreen(product));
  });
};
