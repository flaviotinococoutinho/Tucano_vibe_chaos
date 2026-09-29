import type { FastifyRequest } from 'fastify';
import {
  type Catalog,
  ServiceUnavailable,
  type Store,
  type Trace,
  traceOf,
} from '../upstream/index.ts';
import { Cache } from './cache.ts';
import { STORE_SLUG, StoreNotFound } from './store.ts';

/** A store is asked of the catalog once a minute at most, per process (ADR 0031). */
const KEEP_FOR_MS = 60_000;

/** Far more stores than the platform hosts, and few enough never to weigh on the process. */
const MAX_KEPT = 64;

export type StoresOptions = {
  readonly catalog: Catalog;
  /** How long a store answers before the catalog is asked again; a minute unless a test says. */
  readonly keepForMs?: number;
  /** The most stores kept at once. */
  readonly maxKept?: number;
  /** The clock, in milliseconds, which a test moves by hand. */
  readonly now?: () => number;
};

/**
 * The stores of the platform, as the catalog keeps them, and the store each request is in.
 * Every store screen needs its store, so the catalog would be asked on every one of them:
 * the stores stay in memory for a minute instead, and past it a store still answers while
 * the catalog is asked again behind it. A catalog that is out or slow then holds only the
 * screens that show products; the orders and the tracking of a store the BFF knows go on.
 */
export type Stores = {
  /** Every store of the platform, sorted by name, as the catalog lists them. */
  all(trace: Trace): Promise<readonly Store[]>;
  /** The store with that slug, or null when the platform has none. */
  find(slug: string, trace: Trace): Promise<Store | null>;
  /**
   * onRequest hook of the store screens: the store of the address (`/v1/stores/:store`), kept
   * for the request, or a 404 before anything else runs, a form or a session included.
   */
  enter(request: FastifyRequest): Promise<void>;
  /** The store the request is in, or null on a screen of the platform. */
  of(request: FastifyRequest): Store | null;
  /**
   * The store of a store screen. Only the routes under `/v1/stores/:store` ask, and those
   * always enter their store first.
   */
  entered(request: FastifyRequest): Store;
};

export function storesFrom({
  catalog,
  keepForMs = KEEP_FOR_MS,
  maxKept = MAX_KEPT,
  now = () => performance.now(),
}: StoresOptions): Stores {
  const bySlug = new Cache<Store>({ keepForMs, maxEntries: maxKept, now });
  const everyStore = new Cache<readonly Store[]>({ keepForMs, maxEntries: 1, now });
  const where = new WeakMap<FastifyRequest, Store>();

  const find = async (slug: string, trace: Trace): Promise<Store | null> =>
    STORE_SLUG.test(slug)
      ? bySlug.get(slug, () => catalog.store(slug, trace), behind(trace))
      : null;

  return {
    async all(trace) {
      const stores = await everyStore.get(
        'all',
        async () => {
          const listed = await catalog.stores(trace);
          // The list tells each store too: entering one of them right after asks nobody.
          for (const store of listed) {
            bySlug.put(store.slug, store);
          }
          return listed;
        },
        behind(trace),
      );

      return stores ?? [];
    },

    find,

    async enter(request) {
      const store = await find(slugIn(request), traceOf(request));
      if (store === null) {
        throw new StoreNotFound();
      }
      where.set(request, store);
    },

    of: (request) => where.get(request) ?? null,

    entered(request) {
      const store = where.get(request);
      if (store === undefined) {
        throw new Error('A store screen always enters its store first.');
      }
      return store;
    },
  };
}

/** What a call made behind a store that already answered does when it fails. */
function behind(trace: Trace): (error: unknown) => void {
  return (error) => {
    // A timeout or an outage already left its warn line, with the call and the time it took.
    if (!(error instanceof ServiceUnavailable)) {
      trace.log.error({ err: error }, 'kept a store the catalog could not confirm');
    }
  };
}

function slugIn(request: FastifyRequest): string {
  const { params } = request;
  if (typeof params !== 'object' || params === null || !('store' in params)) {
    return '';
  }
  return typeof params.store === 'string' ? params.store : '';
}
