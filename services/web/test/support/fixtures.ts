import { readFileSync } from 'node:fs';
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
  orderAwaitingPayment: loadScreen('order-awaiting-payment'),
  orderPendingPayment: loadScreen('order-pending-payment'),
  orderShipped: loadScreen('order-shipped'),
  tracking: loadScreen('tracking'),
  validationProblem: loadProblem('validation'),
};

export const allScreenFixtures: readonly SirenScreen[] = [
  fixtures.home,
  fixtures.catalog,
  fixtures.product,
  fixtures.checkout,
  fixtures.orderAwaitingPayment,
  fixtures.orderPendingPayment,
  fixtures.orderShipped,
  fixtures.tracking,
];
