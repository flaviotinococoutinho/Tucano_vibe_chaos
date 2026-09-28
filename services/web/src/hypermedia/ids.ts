/**
 * Branded value types for the two addresses the app juggles, so a raw `string` never
 * passes for one by accident. Both are opaque: the web never parses what is inside them,
 * only maps between the two (see `prefix.ts`).
 */

/** The address the browser shows, e.g. `/products/BOOK-DDD-001` or `/`. Always starts with `/`. */
export type BrowserPath = string & { readonly __brand: 'BrowserPath' };

export const BrowserPath = {
  of(value: string): BrowserPath {
    return normalize(value) as BrowserPath;
  },
};

/** An absolute-path reference the BFF handed out: an action's `href`, a link's, a `Location`. */
export type BffHref = string & { readonly __brand: 'BffHref' };

export const BffHref = {
  of(value: string): BffHref {
    return normalize(value) as BffHref;
  },
};

/** A tracking code, e.g. `TX02PX83Y5M5G00`. Opaque: the field's own `pattern` validates it. */
export type TrackingCode = string & { readonly __brand: 'TrackingCode' };

export const TrackingCode = {
  of(value: string): TrackingCode {
    return value as TrackingCode;
  },
};

function normalize(value: string): string {
  return value.startsWith('/') ? value : `/${value}`;
}
