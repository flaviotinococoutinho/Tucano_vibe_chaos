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
      upstreams: {
        catalog: 'http://toxiproxy:18081',
        commerce: 'http://toxiproxy:18082',
        logistics: 'http://toxiproxy:18083',
      },
      upstreamTimeoutMs: 5000,
      trackingLivePath: '/api/tracking/v1/live',
    });
  });

  it('reads every value from the environment', () => {
    const config = loadConfig({
      APP_NAME: 'bff-canary',
      APP_ENV: 'local',
      HOST: '127.0.0.1',
      PORT: '3100',
      LOG_LEVEL: 'DEBUG',
      CATALOG_URL: 'http://nginx:8081/',
      COMMERCE_URL: 'http://nginx:8082',
      LOGISTICS_URL: 'https://logistics.internal',
      UPSTREAM_TIMEOUT_MS: '2500',
      TRACKING_LIVE_PATH: '/live/tracking',
    });

    assert.deepEqual(config, {
      serviceName: 'bff-canary',
      environment: 'local',
      host: '127.0.0.1',
      port: 3100,
      logLevel: 'debug',
      upstreams: {
        catalog: 'http://nginx:8081',
        commerce: 'http://nginx:8082',
        logistics: 'https://logistics.internal',
      },
      upstreamTimeoutMs: 2500,
      trackingLivePath: '/live/tracking',
    });
  });

  it('treats an empty variable as unset', () => {
    assert.deepEqual(loadConfig({ PORT: '', APP_ENV: ' ' }), loadConfig({}));
  });

  it('runs an unknown environment as production, like the PHP services', () => {
    assert.equal(loadConfig({ APP_ENV: 'qa' }).environment, 'production');
  });

  it('refuses a service address that is not an HTTP URL, and a timeout of zero', () => {
    assert.throws(() => loadConfig({ COMMERCE_URL: 'nginx:8082', UPSTREAM_TIMEOUT_MS: '0' }), {
      name: 'InvalidConfig',
      message:
        'Invalid configuration: COMMERCE_URL must be an http or https URL, got "nginx:8082"; ' +
        'UPSTREAM_TIMEOUT_MS must be an integer from 1 to 60000, got "0".',
    });
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

  it('refuses a tracking live path that is not a path on the origin', () => {
    assert.throws(() => loadConfig({ TRACKING_LIVE_PATH: 'wss://tracking/v1/live' }), {
      name: 'InvalidConfig',
      message:
        'Invalid configuration: TRACKING_LIVE_PATH must start with "/", got ' +
        '"wss://tracking/v1/live".',
    });
  });
});
