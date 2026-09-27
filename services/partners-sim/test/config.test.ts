import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { loadConfig } from '../src/config.ts';

describe('config', () => {
  it('falls back to defaults that fit the compose stack', () => {
    assert.deepEqual(loadConfig({}), {
      serviceName: 'partners-sim',
      environment: 'production',
      host: '0.0.0.0',
      port: 4000,
      logLevel: 'info',
      payfake: {
        webhook: {
          url: 'http://kong:8000/api/commerce/v1/webhooks/payfake',
          secret: 'whsec_local_payfake',
        },
        processingDelayMs: { min: 300, max: 1500 },
      },
    });
  });

  it('reads every value from the environment', () => {
    const config = loadConfig({
      SERVICE_NAME: 'partners-sim-canary',
      APP_ENV: 'local',
      HOST: '127.0.0.1',
      PORT: '4100',
      LOG_LEVEL: 'DEBUG',
      PAYFAKE_WEBHOOK_URL: 'https://merchant.example/webhooks',
      PAYFAKE_WEBHOOK_SECRET: 'whsec_rotated',
      PAYFAKE_PROCESSING_MIN_MS: '0',
      PAYFAKE_PROCESSING_MAX_MS: '50',
    });

    assert.deepEqual(config, {
      serviceName: 'partners-sim-canary',
      environment: 'local',
      host: '127.0.0.1',
      port: 4100,
      logLevel: 'debug',
      payfake: {
        webhook: { url: 'https://merchant.example/webhooks', secret: 'whsec_rotated' },
        processingDelayMs: { min: 0, max: 50 },
      },
    });
  });

  it('treats an empty variable as unset', () => {
    const empty = { PORT: '', APP_ENV: ' ', PAYFAKE_WEBHOOK_SECRET: '', PAYFAKE_WEBHOOK_URL: '' };

    assert.deepEqual(loadConfig(empty), loadConfig({}));
  });

  it('runs an unknown environment as production, like the PHP services', () => {
    assert.equal(loadConfig({ APP_ENV: 'qa' }).environment, 'production');
  });

  it('refuses values it cannot run with and names each one', () => {
    assert.throws(() => loadConfig({ PORT: '70000', LOG_LEVEL: 'loud' }), {
      name: 'InvalidConfig',
      service: 'partners-sim',
      message:
        'Invalid configuration: PORT must be an integer from 1 to 65535, got "70000"; ' +
        'LOG_LEVEL must be one of fatal, error, warn, info, debug, trace, silent, got "loud".',
    });
  });

  it('refuses PayFake settings it cannot run with', () => {
    const invalid = {
      PAYFAKE_WEBHOOK_URL: 'kong:8000/webhooks',
      PAYFAKE_PROCESSING_MIN_MS: 'soon',
      PAYFAKE_PROCESSING_MAX_MS: '900000',
    };

    assert.throws(() => loadConfig(invalid), {
      name: 'InvalidConfig',
      message:
        'Invalid configuration: ' +
        'PAYFAKE_PROCESSING_MIN_MS must be an integer from 0 to 600000, got "soon"; ' +
        'PAYFAKE_PROCESSING_MAX_MS must be an integer from 0 to 600000, got "900000"; ' +
        'PAYFAKE_WEBHOOK_URL must be an http or https URL, got "kong:8000/webhooks".',
    });
  });

  it('refuses a processing delay whose maximum is below its minimum', () => {
    const inverted = { PAYFAKE_PROCESSING_MIN_MS: '2000', PAYFAKE_PROCESSING_MAX_MS: '1000' };

    assert.throws(() => loadConfig(inverted), {
      message:
        'Invalid configuration: PAYFAKE_PROCESSING_MAX_MS must be at least ' +
        'PAYFAKE_PROCESSING_MIN_MS (2000), got "1000".',
    });
  });
});
