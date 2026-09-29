import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { SirenScreen } from '../../src/siren/index.ts';

/**
 * The real example screens of the contract, read from their repo path, never copied. Assertions
 * in tests should read the values they expect from these objects instead of retyping them, so a
 * fixture the BFF team edits later does not leave a stale literal behind.
 */
const EXAMPLES_DIR = join(
  import.meta.dirname,
  '..',
  '..',
  '..',
  '..',
  'contracts',
  'http',
  'bff',
  'examples',
);

export type ProblemBody = {
  readonly type: string;
  readonly title: string;
  readonly status: number;
  readonly detail: string;
  readonly instance: string;
  readonly correlationId: string;
  readonly errors?: Readonly<Record<string, readonly string[]>>;
};

function loadScreen(name: string): SirenScreen {
  return JSON.parse(readFileSync(join(EXAMPLES_DIR, `${name}.json`), 'utf8')) as SirenScreen;
}

function loadProblem(name: string): ProblemBody {
  return JSON.parse(
    readFileSync(join(EXAMPLES_DIR, 'problems', `${name}.json`), 'utf8'),
  ) as ProblemBody;
}

export const fixtures = {
  home: loadScreen('home'),
  catalog: loadScreen('catalog'),
  product: loadScreen('product'),
  checkout: loadScreen('checkout'),
  profiles: loadScreen('profiles'),
  orders: loadScreen('orders'),
  ordersEmpty: loadScreen('orders-empty'),
  orderAwaitingPayment: loadScreen('order-awaiting-payment'),
  orderCancelled: loadScreen('order-cancelled'),
  orderDelivered: loadScreen('order-delivered'),
  orderOutForDelivery: loadScreen('order-out-for-delivery'),
  orderPaid: loadScreen('order-paid'),
  orderPendingPayment: loadScreen('order-pending-payment'),
  orderShipped: loadScreen('order-shipped'),
  orderWithoutDeliveryNews: loadScreen('order-without-delivery-news'),
  tracking: loadScreen('tracking'),
  trackingLive: loadScreen('tracking-live'),
  validationProblem: loadProblem('validation'),
};

/**
 * Every screen in the examples folder, listed from disk: an example the BFF adds is
 * rendered by the tests the day it lands, without anyone editing a list.
 */
export const allScreenFixtures: readonly SirenScreen[] = readdirSync(EXAMPLES_DIR)
  .filter((file) => file.endsWith('.json'))
  .sort()
  .map((file) => loadScreen(file.replace(/\.json$/, '')));
