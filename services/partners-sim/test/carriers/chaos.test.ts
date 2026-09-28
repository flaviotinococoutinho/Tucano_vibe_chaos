import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { CALM } from '../../src/carriers/chaos.ts';
import { carriersApp, type LogLine, putChaos } from '../support/carriers.ts';

describe('carriers chaos controls', () => {
  it('start calm', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());

    const response = await app.inject({ method: 'GET', url: '/_chaos/carriers' });

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), CALM);
  });

  it('take a whole experiment per PUT: every knob left out goes back to calm', async (t) => {
    const logs: LogLine[] = [];
    const app = carriersApp({ logs });
    t.after(() => app.close());
    await putChaos(app, { refusalRate: 0.2 });

    const response = await putChaos(app, {
      failureRate: 0.3,
      webhooks: { dropRate: 0.5 },
    });

    const expected = {
      ...CALM,
      failureRate: 0.3,
      refusalRate: 0,
      webhooks: { ...CALM.webhooks, dropRate: 0.5 },
    };
    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), expected);
    assert.deepEqual(
      (await app.inject({ method: 'GET', url: '/_chaos/carriers' })).json(),
      expected,
    );
    assert.partialDeepStrictEqual(
      logs.findLast((line) => line.settings !== undefined),
      { level: 'info', message: 'chaos settings changed', settings: expected },
    );
  });

  it('go back to calm on DELETE', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());
    await putChaos(app, { failureRate: 1, webhooks: { delayMs: 1000 } });

    const response = await app.inject({ method: 'DELETE', url: '/_chaos/carriers' });

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), CALM);
  });

  it('refuse settings they cannot run with, name the field and keep the old ones', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());
    const cases: [object, Record<string, string[]>][] = [
      [{ failureRate: 1.5 }, { failureRate: ['must be <= 1'] }],
      [{ refusalRate: -0.1 }, { refusalRate: ['must be >= 0'] }],
      [{ failureRate: '0.5' }, { failureRate: ['must be number'] }],
      [{ webhooks: { delayMs: 1.5 } }, { 'webhooks.delayMs': ['must be integer'] }],
      [{ webhooks: { dropRate: 2 } }, { 'webhooks.dropRate': ['must be <= 1'] }],
      [{ latencyMs: { min: 0, max: 100 } }, { body: ['must NOT have additional properties'] }],
    ];

    for (const [settings, errors] of cases) {
      const response = await putChaos(app, settings);

      assert.equal(response.statusCode, 422, JSON.stringify(settings));
      assert.partialDeepStrictEqual(response.json(), { title: 'Unprocessable Content', errors });
    }
    assert.deepEqual((await app.inject({ method: 'GET', url: '/_chaos/carriers' })).json(), CALM);
  });

  it('refuses failureRate and refusalRate adding up to more than 1, and keeps the old ones', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());
    await putChaos(app, { failureRate: 0.4 });

    const response = await putChaos(app, { failureRate: 0.7, refusalRate: 0.4 });

    assert.equal(response.statusCode, 422);
    assert.partialDeepStrictEqual(response.json(), {
      title: 'Unprocessable Content',
      detail: 'failureRate and refusalRate must add up to at most 1, got 0.7 and 0.4.',
    });
    assert.equal(
      (await app.inject({ method: 'GET', url: '/_chaos/carriers' })).json().failureRate,
      0.4,
    );
  });

  it('accepts failureRate and refusalRate adding up to exactly 1', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());

    const response = await putChaos(app, { failureRate: 0.6, refusalRate: 0.4 });

    assert.equal(response.statusCode, 200);
    assert.equal(response.json().failureRate, 0.6);
    assert.equal(response.json().refusalRate, 0.4);
  });
});
