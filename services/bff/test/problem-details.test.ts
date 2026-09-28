import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildApp } from '../src/app.ts';
import { loadConfig } from '../src/config.ts';
import { DomainError } from '../src/platform/domain-error.ts';

const config = loadConfig({ LOG_LEVEL: 'silent' });

class InsufficientStock extends DomainError {
  readonly category = 'conflict';
}

class ProviderOut extends DomainError {
  readonly category = 'unavailable';
}

class InvalidAddress extends DomainError {
  readonly category = 'invalid_input';
}

describe('problem details', () => {
  it('answers unknown routes with a 404 problem', async () => {
    const response = await buildApp({ config }).inject({
      method: 'GET',
      url: '/v1/nothing-here',
      headers: { 'x-correlation-id': 'req-1#1' },
    });

    assert.equal(response.statusCode, 404);
    assert.match(String(response.headers['content-type']), /^application\/problem\+json/);
    assert.equal(response.headers['x-correlation-id'], 'req-1#1');
    assert.deepEqual(response.json(), {
      type: 'about:blank',
      title: 'Not Found',
      status: 404,
      detail: 'The route GET /v1/nothing-here could not be found.',
      instance: '/v1/nothing-here',
      correlationId: 'req-1#1',
    });
  });

  it('answers malformed URLs with a 400 problem that carries the id', async () => {
    const response = await buildApp({ config }).inject({
      method: 'GET',
      url: '/%E0%A4%A',
      headers: { 'x-correlation-id': 'req-1#3' },
    });

    assert.equal(response.statusCode, 400);
    assert.match(String(response.headers['content-type']), /^application\/problem\+json/);
    assert.equal(response.headers['x-correlation-id'], 'req-1#3');
    assert.partialDeepStrictEqual(response.json(), {
      title: 'Bad Request',
      status: 400,
      correlationId: 'req-1#3',
    });
  });

  it('turns domain errors into an HTTP status by category', async () => {
    const app = buildApp({ config });
    app.get('/test/conflict', async () => {
      throw new InsufficientStock('Only 2 units of BOOK-DDD-001 left.');
    });

    const response = await app.inject({
      method: 'GET',
      url: '/test/conflict',
      headers: { 'x-correlation-id': 'req-1#2' },
    });

    assert.equal(response.statusCode, 409);
    assert.deepEqual(response.json(), {
      type: 'about:blank',
      title: 'Conflict',
      status: 409,
      detail: 'Only 2 units of BOOK-DDD-001 left.',
      instance: '/test/conflict',
      correlationId: 'req-1#2',
    });
  });

  it('keeps the detail of a domain 503 and says when to try again', async () => {
    const app = buildApp({ config });
    app.get('/test/unavailable', async () => {
      throw new ProviderOut('The payment provider is out; try again in 17 s.', {
        retryAfterSeconds: 17,
      });
    });

    const response = await app.inject({ method: 'GET', url: '/test/unavailable' });

    assert.equal(response.statusCode, 503);
    assert.equal(response.headers['retry-after'], '17');
    assert.partialDeepStrictEqual(response.json(), {
      title: 'Service Unavailable',
      detail: 'The payment provider is out; try again in 17 s.',
    });
  });

  it('lists the messages a domain error keeps per field', async () => {
    const app = buildApp({ config });
    app.post('/test/address', async () => {
      throw new InvalidAddress('Some fields need attention.', {
        fieldErrors: { postalCode: ['A postal code has 8 digits.'] },
      });
    });

    const response = await app.inject({ method: 'POST', url: '/test/address', payload: {} });

    assert.equal(response.statusCode, 422);
    assert.equal(response.headers['retry-after'], undefined);
    assert.partialDeepStrictEqual(response.json(), {
      detail: 'Some fields need attention.',
      errors: { postalCode: ['A postal code has 8 digits.'] },
    });
  });

  it('lists each invalid field when the body breaks the schema', async () => {
    const app = buildApp({ config });
    const body = {
      type: 'object',
      required: ['sku'],
      properties: { sku: { type: 'string' }, quantity: { type: 'integer' } },
    };
    app.post('/test/validation', { schema: { body } }, async () => ({}));

    const response = await app.inject({
      method: 'POST',
      url: '/test/validation',
      payload: { quantity: 2 },
    });

    assert.equal(response.statusCode, 422);
    assert.partialDeepStrictEqual(response.json(), {
      title: 'Unprocessable Content',
      status: 422,
      instance: '/test/validation',
      errors: { sku: ["must have required property 'sku'"] },
    });
  });

  it('keeps the status of client errors raised by Fastify', async () => {
    const app = buildApp({ config });
    app.post('/test/echo', async (request) => request.body);

    const response = await app.inject({
      method: 'POST',
      url: '/test/echo',
      headers: { 'content-type': 'application/json' },
      payload: '{"sku":',
    });

    assert.equal(response.statusCode, 400);
    assert.partialDeepStrictEqual(response.json(), { title: 'Bad Request', status: 400 });
  });

  it('does not leak internals on unexpected errors', async () => {
    const app = buildApp({ config });
    app.get('/test/crash', async () => {
      throw new Error('database password is hunter2');
    });

    const response = await app.inject({ method: 'GET', url: '/test/crash' });

    assert.equal(response.statusCode, 500);
    assert.doesNotMatch(response.body, /hunter2/);
    assert.partialDeepStrictEqual(response.json(), {
      title: 'Internal Server Error',
      detail: 'Something went wrong on our side. Quote the correlation id when reporting it.',
    });
  });
});
