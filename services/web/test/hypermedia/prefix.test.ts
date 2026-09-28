import { describe, expect, it } from 'vitest';
import { BffHref, BrowserPath, toBffHref, toBrowserPath } from '../../src/hypermedia/index.ts';

describe('toBffHref', () => {
  it('prefixes the browser path', () => {
    expect(toBffHref(BrowserPath.of('/products'))).toBe('/bff/v1/products');
  });

  it('maps the root path to the bare prefix', () => {
    expect(toBffHref(BrowserPath.of('/'))).toBe('/bff/v1');
  });

  it('keeps the query string', () => {
    expect(toBffHref(BrowserPath.of('/tracking?code=TX02PX83Y5M5G00'))).toBe(
      '/bff/v1/tracking?code=TX02PX83Y5M5G00',
    );
  });
});

describe('toBrowserPath', () => {
  it('strips the prefix', () => {
    expect(toBrowserPath(BffHref.of('/bff/v1/products/BOOK-DDD-001'))).toBe(
      '/products/BOOK-DDD-001',
    );
  });

  it('maps the bare prefix to the root path', () => {
    expect(toBrowserPath(BffHref.of('/bff/v1'))).toBe('/');
  });

  it('accepts an absolute URL, the shape response.url gives back after a redirect', () => {
    expect(toBrowserPath('http://localhost:5173/bff/v1/tracking/TX02PX83Y5M5G00')).toBe(
      '/tracking/TX02PX83Y5M5G00',
    );
  });

  it('round-trips with toBffHref', () => {
    const path = BrowserPath.of('/products/BOOK-DDD-001');
    expect(toBrowserPath(toBffHref(path))).toBe(path);
  });
});
