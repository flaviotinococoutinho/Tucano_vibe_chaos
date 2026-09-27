import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import { describe, it } from 'node:test';

describe('server', () => {
  it('refuses to start with an invalid config and says why in one JSON line', () => {
    const server = join(import.meta.dirname, '../src/server.ts');

    const result = spawnSync(process.execPath, [server], {
      env: { PATH: process.env.PATH, PORT: 'http' },
      encoding: 'utf8',
      timeout: 10_000,
    });

    assert.equal(result.status, 1);
    assert.partialDeepStrictEqual(JSON.parse(result.stderr), {
      level: 'fatal',
      service: 'bff',
      message: 'Invalid configuration: PORT must be an integer from 1 to 65535, got "http".',
    });
  });
});
