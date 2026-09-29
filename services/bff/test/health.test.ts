import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { setTimeout as sleep } from 'node:timers/promises';
import { buildApp } from '../src/app.ts';
import type { HealthCheck } from '../src/platform/readiness.ts';
import { testConfig } from './support/config.ts';

const config = testConfig();

const up = (name: string): HealthCheck => ({ name, check: async () => {} });

const down = (name: string, failure: string): HealthCheck => ({
  name,
  check: async () => {
    throw new Error(failure);
  },
});

describe('health', () => {
  it('liveness only says the process is up', async () => {
    const response = await buildApp({ config }).inject({ method: 'GET', url: '/health/live' });

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), { status: 'up' });
  });

  it('readiness is up with no checks while the service has no dependencies', async () => {
    const response = await buildApp({ config }).inject({ method: 'GET', url: '/health/ready' });

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), { status: 'up', checks: {} });
  });

  it('readiness reports every dependency with how long it took', async () => {
    let slowCheckTook = 0;
    const slow: HealthCheck = {
      name: 'slow',
      check: async () => {
        const startedAt = performance.now();
        await sleep(20);
        slowCheckTook = performance.now() - startedAt;
      },
    };
    const app = buildApp({ config, checks: [slow, up('fast')] });

    const response = await app.inject({ method: 'GET', url: '/health/ready' });

    assert.equal(response.statusCode, 200);
    const { status, checks } = response.json();
    assert.equal(status, 'up');
    assert.deepEqual(checks, {
      slow: { status: 'up', latencyMs: checks.slow.latencyMs },
      fast: { status: 'up', latencyMs: checks.fast.latencyMs },
    });
    // Timers may fire a little before 20 ms, so the bound is what the check itself measured.
    assert.ok(Number.isInteger(checks.slow.latencyMs));
    assert.ok(checks.slow.latencyMs >= Math.floor(slowCheckTook));
  });

  it('readiness fails when one dependency is down', async () => {
    const app = buildApp({ config, checks: [up('healthy'), down('broken', 'Connection refused')] });

    const response = await app.inject({ method: 'GET', url: '/health/ready' });

    assert.equal(response.statusCode, 503);
    const { status, checks } = response.json();
    assert.equal(status, 'down');
    assert.deepEqual(checks, {
      healthy: { status: 'up', latencyMs: checks.healthy.latencyMs },
      broken: { status: 'down', latencyMs: checks.broken.latencyMs, error: 'Connection refused' },
    });
  });
});
