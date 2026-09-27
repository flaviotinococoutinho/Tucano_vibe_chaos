import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildApp } from '../src/app.ts';
import { loadConfig } from '../src/config.ts';
import type { HealthCheck } from '../src/platform/readiness.ts';

type LogLine = Record<string, unknown>;

const config = loadConfig({ LOG_LEVEL: 'info' });

const ISO_8601_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/;

function appLoggingTo(lines: LogLine[], checks: readonly HealthCheck[] = []) {
  return buildApp({
    config,
    checks,
    logStream: { write: (line) => lines.push(JSON.parse(line)) },
  });
}

describe('logging', () => {
  it('writes the service and the correlation id on every line of a request', async () => {
    const lines: LogLine[] = [];

    await appLoggingTo(lines).inject({
      method: 'GET',
      url: '/v1/nothing-here',
      headers: { 'x-correlation-id': 'req-7#1' },
    });

    assert.deepEqual(
      lines.map((line) => line.message),
      ['incoming request', 'request completed'],
    );
    for (const line of lines) {
      assert.equal(line.level, 'info');
      assert.match(String(line.timestamp), ISO_8601_UTC);
      assert.equal(line.service, config.serviceName);
      assert.equal(line.correlation_id, 'req-7#1');
    }
  });

  it('leaves health probes out of the request log', async () => {
    const lines: LogLine[] = [];
    const app = appLoggingTo(lines);

    await app.inject({ method: 'GET', url: '/health/live' });
    await app.inject({ method: 'GET', url: '/health/ready' });

    assert.deepEqual(lines, []);
  });

  it('still warns when a probe finds a dependency down', async () => {
    const lines: LogLine[] = [];
    const broken: HealthCheck = {
      name: 'broken',
      check: async () => {
        throw new Error('Connection refused');
      },
    };

    await appLoggingTo(lines, [broken]).inject({ method: 'GET', url: '/health/ready' });

    assert.deepEqual(
      lines.map((line) => [line.level, line.message]),
      [['warn', 'not ready']],
    );
  });
});
