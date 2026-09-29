import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildApp } from '../src/app.ts';
import { testConfig } from './support/config.ts';

const config = testConfig();

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('correlation id', () => {
  it('keeps the id that came from the gateway', async () => {
    const response = await buildApp({ config }).inject({
      method: 'GET',
      url: '/health/live',
      headers: { 'x-correlation-id': '4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12' },
    });

    assert.equal(response.headers['x-correlation-id'], '4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12');
  });

  it('creates a UUIDv7 when the request has none', async () => {
    const response = await buildApp({ config }).inject({ method: 'GET', url: '/health/live' });

    assert.match(String(response.headers['x-correlation-id']), UUID_V7);
  });

  it('creates one when the header comes empty', async () => {
    const response = await buildApp({ config }).inject({
      method: 'GET',
      url: '/health/live',
      headers: { 'x-correlation-id': '' },
    });

    assert.match(String(response.headers['x-correlation-id']), UUID_V7);
  });
});
