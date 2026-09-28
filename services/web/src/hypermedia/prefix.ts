import { BffHref, BrowserPath } from './ids.ts';

/**
 * The only hardcoded URL of the app. Every BFF resource lives under this prefix
 * (`contracts/http/bff/README.md`); everything else the web visits is a link it followed
 * or an action it submitted, both handed out by the server. A guard test
 * (`test/hypermedia/no-hardcoded-urls.test.ts`) fails the build if this literal shows up
 * anywhere else in `src/`.
 */
const BFF_PREFIX = '/bff/v1';

/** `/products/BOOK-DDD-001` (what the browser shows) becomes the address to fetch. */
export function toBffHref(path: BrowserPath): BffHref {
  return BffHref.of(path === '/' ? BFF_PREFIX : `${BFF_PREFIX}${path}`);
}

/**
 * The inverse: a BFF address (an href, a `Location` header, or `response.url` after fetch
 * followed a redirect) becomes the path the browser shows. Accepts an absolute URL or an
 * absolute-path reference; only the path, query and fragment survive.
 */
export function toBrowserPath(href: BffHref | string): BrowserPath {
  const url = new URL(href, window.location.origin);
  const withoutPrefix = url.pathname.startsWith(BFF_PREFIX)
    ? url.pathname.slice(BFF_PREFIX.length)
    : url.pathname;
  const path = withoutPrefix === '' ? '/' : withoutPrefix;
  return BrowserPath.of(`${path}${url.search}${url.hash}`);
}
