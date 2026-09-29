import type { FastifyInstance } from 'fastify';
import { buildApp } from '../../src/app.ts';
import type { LogStream } from '../../src/platform/logging.ts';
import { testConfig } from './config.ts';
import { PAY_KEY } from './example-screens.ts';
import { type FakeServices, ids } from './fake-services.ts';

export type Setup = {
  readonly env?: Readonly<Record<string, string>>;
  /** The ids the BFF makes, in order; by default every form gets the key of the examples. */
  readonly newId?: () => string;
  readonly logStream?: LogStream;
  /** The clock of the stores the BFF keeps in memory, when a test moves time by hand. */
  readonly clock?: () => number;
};

/** The BFF pointed at the fake services, with ids a test can predict. */
export function bffOver(
  services: FakeServices,
  { env = {}, newId = ids(PAY_KEY), logStream, clock }: Setup = {},
): FastifyInstance {
  const config = testConfig({
    CATALOG_URL: services.url,
    COMMERCE_URL: services.url,
    LOGISTICS_URL: services.url,
    UPSTREAM_TIMEOUT_MS: '1000',
    ...env,
  });

  return buildApp({
    config,
    newId,
    ...(logStream === undefined ? {} : { logStream }),
    ...(clock === undefined ? {} : { clock }),
  });
}
