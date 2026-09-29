import type { Fields, Price } from './fields.ts';
import { call, type Trace, type Upstream } from './http.ts';

/**
 * A store of the platform (ADR 0031). The catalog keeps it, and the web draws it with one of
 * the palettes of its design system.
 */
export type Store = {
  /** Immutable, and part of every address of the store: `arara`. */
  readonly slug: string;
  readonly name: string;
  readonly tagline: string;
  /**
   * The palette of the web's design system the store wears. The store picks, the web owns
   * the colors, and a palette the web does not know yet falls back to the look of the platform.
   */
  readonly palette: string;
};

/** Outside the catalog a draft does not exist: a product is for sale or out of line. */
export type ProductStatus = 'active' | 'discontinued';

export type Product = {
  readonly sku: string;
  readonly name: string;
  readonly status: ProductStatus;
  readonly category: string;
  readonly price: Price;
  readonly weightGrams: number;
  readonly dimensions: {
    readonly lengthMm: number;
    readonly widthMm: number;
    readonly heightMm: number;
  };
};

export type ProductPage = {
  readonly products: readonly Product[];
  readonly page: number;
  readonly perPage: number;
  readonly total: number;
};

/** The catalog service (Lumen), in the words the BFF uses. */
export type Catalog = {
  /** Every store of the platform, sorted by name. */
  stores(trace: Trace): Promise<readonly Store[]>;
  /** The store, or null when the catalog has no store with that slug. */
  store(slug: string, trace: Trace): Promise<Store | null>;
  /**
   * A page of the products the store sells, in the order the catalog keeps; null when the
   * catalog has no store with that slug.
   */
  page(store: string, page: number, trace: Trace): Promise<ProductPage | null>;
  /**
   * The product, or null when the store does not sell that SKU: a SKU of another store reads
   * exactly like a SKU that does not exist.
   */
  product(store: string, sku: string, trace: Trace): Promise<Product | null>;
};

export function catalogAt(upstream: Upstream): Catalog {
  return {
    async stores(trace) {
      const answer = await call(upstream, { method: 'GET', path: '/v1/stores' }, trace);
      if (answer.status !== 200) {
        throw answer.unexpected();
      }

      return answer.fields().objects('stores').map(storeOf);
    },

    async store(slug, trace) {
      const answer = await call(upstream, { method: 'GET', path: storePath(slug) }, trace);
      if (answer.status === 404) {
        return null;
      }
      if (answer.status !== 200) {
        throw answer.unexpected();
      }

      return storeOf(answer.fields());
    },

    async page(store, page, trace) {
      const path = `${storePath(store)}/products?page=${page}`;
      const answer = await call(upstream, { method: 'GET', path }, trace);
      if (answer.status === 404) {
        return null;
      }
      if (answer.status !== 200) {
        throw answer.unexpected();
      }
      const fields = answer.fields();

      return {
        products: fields.objects('data').map(productOf),
        page: fields.integer('page'),
        perPage: fields.integer('perPage'),
        total: fields.integer('total'),
      };
    },

    async product(store, sku, trace) {
      const path = `${storePath(store)}/products/${encodeURIComponent(sku)}`;
      const answer = await call(upstream, { method: 'GET', path }, trace);
      if (answer.status === 404) {
        return null;
      }
      if (answer.status !== 200) {
        throw answer.unexpected();
      }

      return productOf(answer.fields());
    },
  };
}

function storePath(slug: string): string {
  return `/v1/stores/${encodeURIComponent(slug)}`;
}

function storeOf(fields: Fields): Store {
  return {
    slug: fields.text('slug'),
    name: fields.text('name'),
    tagline: fields.text('tagline'),
    palette: fields.text('palette'),
  };
}

function productOf(fields: Fields): Product {
  const dimensions = fields.object('dimensions');

  return {
    sku: fields.text('sku'),
    name: fields.text('name'),
    status: fields.oneOf('status', ['active', 'discontinued']),
    category: fields.text('category'),
    price: fields.price('price'),
    weightGrams: fields.integer('weightGrams'),
    dimensions: {
      lengthMm: dimensions.integer('lengthMm'),
      widthMm: dimensions.integer('widthMm'),
      heightMm: dimensions.integer('heightMm'),
    },
  };
}
