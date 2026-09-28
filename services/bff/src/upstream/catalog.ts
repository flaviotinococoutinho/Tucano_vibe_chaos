import type { Fields, Price } from './fields.ts';
import { call, type Trace, type Upstream } from './http.ts';

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
  /** A page of the products for sale, in the order the catalog keeps. */
  page(page: number, trace: Trace): Promise<ProductPage>;
  /** The product, or null when the catalog does not know the SKU. */
  product(sku: string, trace: Trace): Promise<Product | null>;
};

export function catalogAt(upstream: Upstream): Catalog {
  return {
    async page(page, trace) {
      const answer = await call(
        upstream,
        { method: 'GET', path: `/v1/products?page=${page}` },
        trace,
      );
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

    async product(sku, trace) {
      const path = `/v1/products/${encodeURIComponent(sku)}`;
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
