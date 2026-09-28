import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { loadConfig } from '../src/config.ts';

describe('config', () => {
  it('falls back to defaults that fit the compose stack', () => {
    assert.deepEqual(loadConfig({}), {
      serviceName: 'bff',
      environment: 'production',
      host: '0.0.0.0',
      port: 3000,
      logLevel: 'info',
    });
  });

  it('reads every value from the environment', () => {
    const config = loadConfig({
      APP_NAME: 'bff-canary',
      APP_ENV: 'local',
      HOST: '127.0.0.1',
      PORT: '3100',
      LOG_LEVEL: 'DEBUG',
    });

    assert.deepEqual(config, {
      serviceName: 'bff-canary',
      environment: 'local',
      host: '127.0.0.1',
      port: 3100,
      logLevel: 'debug',
    });
  });

  it('treats an empty variable as unset', () => {
    assert.deepEqual(loadConfig({ PORT: '', APP_ENV: ' ' }), loadConfig({}));
  });

  it('runs an unknown environment as production, like the PHP services', () => {
    assert.equal(loadConfig({ APP_ENV: 'qa' }).environment, 'production');
  });

  it('refuses values it cannot run with and names each one', () => {
    assert.throws(() => loadConfig({ PORT: '70000', LOG_LEVEL: 'loud' }), {
      name: 'InvalidConfig',
      service: 'bff',
      message:
        'Invalid configuration: PORT must be an integer from 1 to 65535, got "70000"; ' +
        'LOG_LEVEL must be one of fatal, error, warn, info, debug, trace, silent, got "loud".',
    });
  });
});
