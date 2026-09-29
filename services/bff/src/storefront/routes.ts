import type { FastifyPluginAsync } from 'fastify';
import { PAGE_QUERY, sendScreen } from '../hypermedia/index.ts';
import { DomainError } from '../platform/domain-error.ts';
import { StoreNotFound, type Stores } from '../stores/index.ts';
import { type Catalog, traceOf } from '../upstream/index.ts';
import { catalogScreen, homeScreen, productScreen, storeScreen } from './screens.ts';

export type HomeOptions = { readonly stores: Stores };

export type StorefrontOptions = { readonly catalog: Catalog; readonly stores: Stores };

/** The shape of a SKU the catalog routes accept; anything else is a product that does not exist. */
export const SKU = /^[A-Z0-9][A-Z0-9-]{2,31}$/;

export class ProductNotFound extends DomainError {
  readonly category = 'not_found';

  constructor(sku: string) {
    super(`Não encontrei o produto ${sku}.`);
  }
}

/** The entry point: the one address the web knows by heart, the home of the platform. */
export const homeRoutes: FastifyPluginAsync<HomeOptions> = async (app, { stores }) => {
  app.get('/v1', async (request, reply) =>
    sendScreen(reply, homeScreen(await stores.all(traceOf(request)))),
  );
};

/** The storefront of a store, under `/v1/stores/:store`: its home, its catalog and its products. */
export const storefrontRoutes: FastifyPluginAsync<StorefrontOptions> = async (
  app,
  { catalog, stores },
) => {
  app.get('/', async (request, reply) => sendScreen(reply, storeScreen(stores.entered(request))));

  app.get<{ Querystring: { page?: number } }>(
    '/products',
    { schema: { querystring: PAGE_QUERY } },
    async (request, reply) => {
      const store = stores.entered(request);
      const page = await catalog.page(store.slug, request.query.page ?? 1, traceOf(request));
      if (page === null) {
        throw new StoreNotFound();
      }

      return sendScreen(reply, catalogScreen(store, page));
    },
  );

  app.get<{ Params: { sku: string } }>('/products/:sku', async (request, reply) => {
    const store = stores.entered(request);
    const { sku } = request.params;
    const product = SKU.test(sku) ? await catalog.product(store.slug, sku, traceOf(request)) : null;
    if (product === null) {
      throw new ProductNotFound(sku);
    }

    return sendScreen(reply, productScreen(store, product));
  });
};
