import {
  type Action,
  component,
  type Entity,
  hidden,
  href,
  type Link,
  money,
  pageLinks,
  path,
  rel,
  screen,
} from '../hypermedia/index.ts';
import { trackByCodeAction } from '../tracking/index.ts';
import { MAX_UNITS_PER_ITEM, type Product, type ProductPage } from '../upstream/index.ts';

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

const HOME: Link = { rel: [rel.up], href: href(''), title: 'Início' };

export function homeScreen(): Entity {
  return screen('home', {
    title: 'Tucano',
    properties: {
      headline: 'Tudo o que você pede, entregue por quem cuida do caminho.',
      tagline: 'Compre, pague e acompanhe a entrega do começo ao fim.',
    },
    actions: [trackByCodeAction()],
    links: [
      { rel: [rel.self], href: href('') },
      { rel: [rel.catalog], href: href('/products'), title: 'Ver o catálogo' },
    ],
  });
}

export function catalogScreen(page: ProductPage): Entity {
  const at = (number: number): string => href('/products', { page: number });

  return screen('catalog', {
    title: 'Catálogo',
    properties: { page: page.page, perPage: page.perPage, total: page.total },
    entities: page.products.map(productCard),
    links: [...pageLinks(page, at), HOME],
  });
}

export function productScreen(product: Product): Entity {
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
    actions: forSale ? [buyAction(product.sku)] : [],
    links: [
      { rel: [rel.self], href: href(path`/products/${product.sku}`) },
      { rel: [rel.collection], href: href('/products'), title: 'Voltar ao catálogo' },
      HOME,
    ],
  });
}

function productCard(product: Product): Entity {
  return component('product-card', {
    rel: [rel.item],
    properties: productFacts(product),
    links: [{ rel: [rel.self], href: href(path`/products/${product.sku}`) }],
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

/** Takes the product and how many units to the checkout, where the order is filled in. */
function buyAction(sku: string): Action {
  return {
    name: 'buy',
    title: 'Comprar',
    method: 'GET',
    href: href('/checkout'),
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
