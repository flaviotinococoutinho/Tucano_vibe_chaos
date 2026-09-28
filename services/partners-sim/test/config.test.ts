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
      webhooks: { retryDelaysMs: [1000, 2000, 4000, 8000, 16000], attemptTimeoutMs: 5000 },
      payfake: {
        webhook: {
          url: 'http://kong:8000/api/commerce/v1/webhooks/payfake',
          secret: 'whsec_local_payfake',
        },
        processingDelayMs: { min: 300, max: 1500 },
        retention: { ttlMs: 86_400_000, maxEntries: 20_000 },
        timeoutHoldMs: 30000,
      },
      carriers: {
        webhook: {
          url: 'http://kong:8000/api/logistics/v1/webhooks/carriers',
          secret: 'whsec_local_carriers',
        },
        stepDelayMs: { min: 1000, max: 4000 },
        retention: { ttlMs: 86_400_000, maxEntries: 20_000 },
      },
    });
  });

  it('reads every value from the environment', () => {
    const config = loadConfig({
      APP_NAME: 'partners-sim-canary',
      APP_ENV: 'local',
      HOST: '127.0.0.1',
      PORT: '4100',
      LOG_LEVEL: 'DEBUG',
      PAYFAKE_WEBHOOK_URL: 'https://merchant.example/webhooks',
      PAYFAKE_WEBHOOK_SECRET: 'whsec_rotated',
      PAYFAKE_PROCESSING_MIN_MS: '0',
      PAYFAKE_PROCESSING_MAX_MS: '50',
      CARRIERS_WEBHOOK_URL: 'https://logistics.example/webhooks',
      CARRIERS_WEBHOOK_SECRET: 'whsec_carriers_rotated',
      CARRIERS_STEP_MIN_MS: '10',
      CARRIERS_STEP_MAX_MS: '60',
      WEBHOOKS_RETRY_DELAYS_MS: '100, 200',
      WEBHOOKS_ATTEMPT_TIMEOUT_MS: '1500',
      PAYFAKE_RETENTION_HOURS: '1',
      PAYFAKE_RETENTION_MAX_ENTRIES: '500',
      PAYFAKE_TIMEOUT_HOLD_MS: '10000',
      CARRIERS_RETENTION_HOURS: '2',
      CARRIERS_RETENTION_MAX_ENTRIES: '800',
    });

    assert.deepEqual(config, {
      serviceName: 'partners-sim-canary',
      environment: 'local',
      host: '127.0.0.1',
      port: 4100,
      logLevel: 'debug',
      webhooks: { retryDelaysMs: [100, 200], attemptTimeoutMs: 1500 },
      payfake: {
        webhook: { url: 'https://merchant.example/webhooks', secret: 'whsec_rotated' },
        processingDelayMs: { min: 0, max: 50 },
        retention: { ttlMs: 3_600_000, maxEntries: 500 },
        timeoutHoldMs: 10000,
      },
      carriers: {
        webhook: { url: 'https://logistics.example/webhooks', secret: 'whsec_carriers_rotated' },
        stepDelayMs: { min: 10, max: 60 },
        retention: { ttlMs: 7_200_000, maxEntries: 800 },
      },
    });
  });

  it('treats an empty variable as unset', () => {
    const empty = {
      PORT: '',
      APP_ENV: ' ',
      PAYFAKE_WEBHOOK_SECRET: '',
      PAYFAKE_WEBHOOK_URL: '',
      CARRIERS_WEBHOOK_SECRET: '',
      CARRIERS_WEBHOOK_URL: '',
    };

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

  it('refuses CarrierFake settings it cannot run with', () => {
    const invalid = {
      CARRIERS_WEBHOOK_URL: 'kong:8000/webhooks',
      CARRIERS_STEP_MIN_MS: 'soon',
      CARRIERS_STEP_MAX_MS: '900000',
    };

    assert.throws(() => loadConfig(invalid), {
      name: 'InvalidConfig',
      message:
        'Invalid configuration: ' +
        'CARRIERS_STEP_MIN_MS must be an integer from 0 to 600000, got "soon"; ' +
        'CARRIERS_STEP_MAX_MS must be an integer from 0 to 600000, got "900000"; ' +
        'CARRIERS_WEBHOOK_URL must be an http or https URL, got "kong:8000/webhooks".',
    });
  });

  it('refuses webhook and retention settings it cannot run with', () => {
    const invalid = {
      WEBHOOKS_RETRY_DELAYS_MS: '1000,soon',
      PAYFAKE_RETENTION_HOURS: '0',
      CARRIERS_RETENTION_MAX_ENTRIES: 'all',
    };

    assert.throws(() => loadConfig(invalid), {
      name: 'InvalidConfig',
      message:
        'Invalid configuration: ' +
        'WEBHOOKS_RETRY_DELAYS_MS must be a comma separated list of integers from 0 to 600000, got "1000,soon"; ' +
        'PAYFAKE_RETENTION_HOURS must be an integer from 1 to 720, got "0"; ' +
        'CARRIERS_RETENTION_MAX_ENTRIES must be an integer from 1 to 1000000, got "all".',
    });
  });

  it('refuses a step delay whose maximum is below its minimum', () => {
    const inverted = { CARRIERS_STEP_MIN_MS: '5000', CARRIERS_STEP_MAX_MS: '1000' };

    assert.throws(() => loadConfig(inverted), {
      message:
        'Invalid configuration: CARRIERS_STEP_MAX_MS must be at least ' +
        'CARRIERS_STEP_MIN_MS (5000), got "1000".',
    });
  });
});
