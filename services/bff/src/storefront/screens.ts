import {
  type Action,
  component,
  type Entity,
  hidden,
  href,
  inStore,
  type Link,
  money,
  pageLinks,
  path,
  rel,
  screen,
} from '../hypermedia/index.ts';
import { brandOf } from '../stores/index.ts';
import { trackByCodeAction } from '../tracking/index.ts';
import {
  MAX_UNITS_PER_ITEM,
  type Product,
  type ProductPage,
  type Store,
} from '../upstream/index.ts';

/**
 * The names people read for the catalog categories. The catalog keeps them too
 * (CategorySeeder); a category the BFF does not know yet shows its code until it
 * gets a name here, because words on a screen are the job of the BFF.
 */
const CATEGORIES: Readonly<Record<string, string>> = {
  books: 'Livros',
  electronics: 'Eletrônicos',
  home: 'Casa',
  sports: 'Esporte',
};

/** The home of the platform: the stores it hosts, and the tracking of any parcel of theirs. */
export function homeScreen(stores: readonly Store[]): Entity {
  return screen('home', {
    title: 'Tucano',
    properties: {
      headline: 'Tudo o que você pede, entregue por quem cuida do caminho.',
      tagline: 'Escolha uma loja, compre e acompanhe a entrega do começo ao fim.',
    },
    entities: stores.map(storeCard),
    actions: [trackByCodeAction(null)],
    links: [{ rel: [rel.self], href: href('') }],
  });
}

/** The home of a store: who it is, the way into its catalog, and the tracking of its parcels. */
export function storeScreen(store: Store): Entity {
  const { slug, name, palette, initial } = brandOf(store);

  return screen('store', {
    title: name,
    properties: { slug, name, tagline: store.tagline, palette, initial },
    actions: [trackByCodeAction(store)],
    links: [
      { rel: [rel.self], href: inStore(store.slug) },
      { rel: [rel.catalog], href: inStore(store.slug, '/products'), title: 'Ver o catálogo' },
    ],
  });
}

export function catalogScreen(store: Store, page: ProductPage): Entity {
  const at = (number: number): string => inStore(store.slug, '/products', { page: number });

  return screen('catalog', {
    title: 'Catálogo',
    properties: { page: page.page, perPage: page.perPage, total: page.total },
    entities: page.products.map((product) => productCard(store, product)),
    links: [...pageLinks(page, at), startOf(store)],
  });
}

export function productScreen(store: Store, product: Product): Entity {
  const forSale = product.status === 'active';

  return screen('product', {
    title: product.name,
    properties: {
      ...productFacts(product),
      weightGrams: product.weightGrams,
      dimensions: product.dimensions,
      ...(forSale
        ? {}
        : {
            notice: {
              tone: 'neutral',
              text: 'Este produto saiu de linha e não está mais à venda.',
            },
          }),
    },
    actions: forSale ? [buyAction(store, product.sku)] : [],
    links: [
      { rel: [rel.self], href: productHref(store, product.sku) },
      {
        rel: [rel.collection],
        href: inStore(store.slug, '/products'),
        title: 'Voltar ao catálogo',
      },
      startOf(store),
    ],
  });
}

/** A store on the home of the platform, with the call to enter it. */
function storeCard(store: Store): Entity {
  const { slug, name, palette, initial } = brandOf(store);

  return component('store-card', {
    rel: [rel.item],
    properties: { slug, name, tagline: store.tagline, palette, initial },
    links: [{ rel: [rel.self], href: inStore(store.slug), title: 'Entrar na loja' }],
  });
}

function productCard(store: Store, product: Product): Entity {
  return component('product-card', {
    rel: [rel.item],
    properties: productFacts(product),
    links: [{ rel: [rel.self], href: productHref(store, product.sku) }],
  });
}

function productFacts(product: Product) {
  return {
    sku: product.sku,
    name: product.name,
    category: product.category,
    categoryLabel: CATEGORIES[product.category] ?? product.category,
    price: money(product.price.amount, product.price.currency),
  };
}

/** Takes the product and how many units to the checkout of the store, where the order is filled in. */
function buyAction(store: Store, sku: string): Action {
  return {
    name: 'buy',
    title: 'Comprar',
    method: 'GET',
    href: inStore(store.slug, '/checkout'),
    fields: [
      hidden('sku', sku),
      {
        name: 'quantity',
        type: 'number',
        title: 'Quantidade',
        value: 1,
        min: 1,
        max: MAX_UNITS_PER_ITEM,
        required: true,
        inputmode: 'numeric',
      },
    ],
  };
}

/** The home of the store, the start of every way back inside it. */
function startOf(store: Store): Link {
  return { rel: [rel.up], href: inStore(store.slug), title: 'Início' };
}

function productHref(store: Store, sku: string): string {
  return inStore(store.slug, path`/products/${sku}`);
}
