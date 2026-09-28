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
/** The couriers' own secret: different from SECRET, so a test catches a report signed wrong. */
export const COURIER_REPORT_SECRET = 'whsec_test_couriers';

/** Nothing listens on the discard port, so webhooks sent here fail fast. */
export const NOWHERE = 'http://127.0.0.1:9/v1/webhooks/carriers';
/** Same discard port, for a test that does not care where the courier's reports go. */
export const NOWHERE_TRACKING = 'http://127.0.0.1:9/api/tracking';

/** A partner shipment crossing two states, so its journey passes through two hubs. */
export const PICKUP = {
  carrier: 'correio-nacional',
  reference: '01a0e30e-0cea-7159-bc05-56a0f4d16cf7',
  trackingCode: 'TX02PRCV4X85G00',
  origin: { center: 'GRU1', state: 'SP' },
  destination: { city: 'Belo Horizonte', state: 'MG', postalCode: '30160011' },
  parcels: 1,
  weightGrams: 400,
} as const;

/** The own fleet, which only ever delivers inside the state of its fulfillment center. */
export const OWN_FLEET_PICKUP = {
  ...PICKUP,
  carrier: 'tucano-express',
  destination: { city: 'Campinas', state: 'SP', postalCode: '13010001' },
} as const;

export const PICKUP_ID = /^pk_[0-9A-HJKMNP-TV-Z]{26}$/;
export const EVENT_ID = /^evt_[0-9A-HJKMNP-TV-Z]{26}$/;

export type CarriersAppOptions = {
  readonly webhookUrl?: string;
  readonly clock?: Clock;
  /** 0.5 by default: any rate above 0.5 strikes, any rate below or at 0.5 does not. */
  readonly random?: Random;
  /** Log lines land here, parsed, at info level. Without it, the app logs nothing. */
  readonly logs?: LogLine[];
  readonly env?: Readonly<Record<string, string>>;
};

export function carriersApp({
  webhookUrl = NOWHERE,
  clock = new InstantClock(),
  random = () => 0.5,
  logs,
  env = {},
}: CarriersAppOptions = {}): FastifyInstance {
  const config = loadConfig({
    LOG_LEVEL: logs === undefined ? 'silent' : 'info',
    CARRIERS_WEBHOOK_URL: webhookUrl,
    CARRIERS_WEBHOOK_SECRET: SECRET,
    // The journey should not wait in a test: every step is one instant tick of InstantClock.
    CARRIERS_STEP_MIN_MS: '1000',
    CARRIERS_STEP_MAX_MS: '1000',
    // Nowhere by default, so a test that is not about the courier device never reaches the
    // network for it; one tick per ride is enough life to exercise the wiring regardless.
    TRACKING_URL: NOWHERE_TRACKING,
    COURIERS_SECRET: COURIER_REPORT_SECRET,
    COURIER_POSITION_INTERVAL_MS: '1000',
    COURIER_RIDE_MS: '1000',
    ...env,
  });
  const logStream = logs && { write: (line: string) => logs.push(JSON.parse(line)) };

  return buildApp({ config, clock, random, logStream });
}

type PickupInput = {
  readonly key?: string;
  readonly body?: object;
  readonly headers?: Readonly<Record<string, string>>;
};

export function postPickup(
  app: FastifyInstance,
  { key = 'shp-1', body = PICKUP, headers = {} }: PickupInput = {},
): Promise<LightMyRequestResponse> {
  return app.inject({
    method: 'POST',
    url: '/carriers/v1/pickups',
    headers: { 'idempotency-key': key, ...headers },
    payload: body,
  });
}

export function getPickup(app: FastifyInstance, id: string): Promise<LightMyRequestResponse> {
  return app.inject({ method: 'GET', url: `/carriers/v1/pickups/${id}` });
}

export function getPickupEvents(app: FastifyInstance, id: string): Promise<LightMyRequestResponse> {
  return app.inject({ method: 'GET', url: `/carriers/v1/pickups/${id}/events` });
}

/** The lookup a merchant makes when it lost the id of a pickup but kept its own reference. */
export function findPickups(
  app: FastifyInstance,
  reference: string,
): Promise<LightMyRequestResponse> {
  return app.inject({ method: 'GET', url: '/carriers/v1/pickups', query: { reference } });
}

export function putChaos(app: FastifyInstance, settings: object): Promise<LightMyRequestResponse> {
  return app.inject({ method: 'PUT', url: '/_chaos/carriers', payload: settings });
}
