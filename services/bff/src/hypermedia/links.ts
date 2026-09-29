const RELATIONS =
  'https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md';

/**
 * RFC 8288 link relations: the registered ones by their names, and the ones of this
 * domain as URIs, because RFC 8288 asks extension types to be URIs. Each URI lands on
 * the definition of the relation in the contract.
 */
export const rel = {
  self: 'self',
  up: 'up',
  collection: 'collection',
  item: 'item',
  next: 'next',
  prev: 'prev',
  catalog: `${RELATIONS}#rel-catalog`,
  track: `${RELATIONS}#rel-track`,
  live: `${RELATIONS}#rel-live`,
  navigation: `${RELATIONS}#rel-navigation`,
  orders: `${RELATIONS}#rel-orders`,
  profiles: `${RELATIONS}#rel-profiles`,
  history: `${RELATIONS}#rel-history`,
} as const;

/** Where the web finds the BFF: Kong routes `/bff` here and strips it, so the BFF itself serves `/v1`. */
export const BFF_ROOT = '/bff/v1';

type Query = Readonly<Record<string, string | number | undefined>>;

/**
 * An address the web can follow: the BFF root, a path and, when given, a query in the
 * order written. A query value left undefined stays out: href('/products', { page: 2 }).
 */
export function href(path: string, query: Query = {}): string {
  const search = new URLSearchParams();
  for (const [name, value] of Object.entries(query)) {
    if (value !== undefined) {
      search.append(name, String(value));
    }
  }

  return search.size > 0 ? `${BFF_ROOT}${path}?${search}` : `${BFF_ROOT}${path}`;
}

/**
 * A path whose interpolated values are each encoded as one segment, so data never
 * changes the shape of an address: path`/orders/${id}/payments`.
 */
export function path(
  parts: TemplateStringsArray,
  ...segments: ReadonlyArray<string | number>
): string {
  let built = parts[0] ?? '';
  segments.forEach((segment, index) => {
    built += encodeURIComponent(String(segment)) + (parts[index + 1] ?? '');
  });

  return built;
}
