import type { FastifyInstance, LightMyRequestResponse } from 'fastify';
import { buildApp } from '../../src/app.ts';
import type { Random } from '../../src/chance.ts';
import type { Clock } from '../../src/clock.ts';
import { loadConfig } from '../../src/config.ts';
import { InstantClock } from './clock.ts';
import { chaosDecisions, type LogLine } from './logs.ts';

export type { LogLine };
export { chaosDecisions };

export const SECRET = 'whsec_test';

/** Nothing listens on the discard port, so webhooks sent here fail fast. */
export const NOWHERE = 'http://127.0.0.1:9/v1/webhooks/payfake';

export const CHARGE = {
  amount: { value: 18990, currency: 'BRL' },
  cardToken: 'tok_visa',
  reference: '01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b',
} as const;

export const CHARGE_ID = /^ch_[0-9A-HJKMNP-TV-Z]{26}$/;

export type PayFakeAppOptions = {
  readonly webhookUrl?: string;
  readonly clock?: Clock;
  /** 0.5 by default: a processing delay of 900 ms, and every chaos rate above 0.5 strikes. */
  readonly random?: Random;
  /** Log lines land here, parsed, at info level. Without it, the app logs nothing. */
  readonly logs?: LogLine[];
  readonly env?: Readonly<Record<string, string>>;
};

export function payfakeApp({
  webhookUrl = NOWHERE,
  clock = new InstantClock(),
  random = () => 0.5,
  logs,
  env = {},
}: PayFakeAppOptions = {}): FastifyInstance {
  const config = loadConfig({
    LOG_LEVEL: logs === undefined ? 'silent' : 'info',
    PAYFAKE_WEBHOOK_URL: webhookUrl,
    PAYFAKE_WEBHOOK_SECRET: SECRET,
    ...env,
  });
  const logStream = logs && { write: (line: string) => logs.push(JSON.parse(line)) };

  return buildApp({ config, clock, random, logStream });
}

type ChargeInput = {
  readonly key?: string;
  readonly body?: object;
  readonly headers?: Readonly<Record<string, string>>;
};

export function postCharge(
  app: FastifyInstance,
  { key = 'pay-1', body = CHARGE, headers = {} }: ChargeInput = {},
): Promise<LightMyRequestResponse> {
  return app.inject({
    method: 'POST',
    url: '/payfake/v1/charges',
    headers: { 'idempotency-key': key, ...headers },
    payload: body,
  });
}

export function getCharge(app: FastifyInstance, id: string): Promise<LightMyRequestResponse> {
  return app.inject({ method: 'GET', url: `/payfake/v1/charges/${id}` });
}

/** The lookup a merchant makes when it lost the id of a charge but kept its own reference. */
export function findCharges(
  app: FastifyInstance,
  reference: string,
): Promise<LightMyRequestResponse> {
  return app.inject({ method: 'GET', url: '/payfake/v1/charges', query: { reference } });
}

export function putChaos(app: FastifyInstance, settings: object): Promise<LightMyRequestResponse> {
  return app.inject({ method: 'PUT', url: '/_chaos/payfake', payload: settings });
}
