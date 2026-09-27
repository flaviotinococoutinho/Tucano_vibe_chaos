import { DomainError } from '../platform/domain-error.ts';

/** An amount in the smallest unit of its currency (centavos for BRL), as commerce sends it. */
export type Money = { readonly value: number; readonly currency: string };

/** Why PayFake refused a charge, in its own words. Commerce translates them at its border. */
export type FailureCode = 'card_declined' | 'insufficient_funds';

/** Only full refunds exist for now, so a charge has at most one. */
export type Refund = {
  readonly id: string;
  readonly chargeId: string;
  readonly status: 'processing' | 'succeeded';
  readonly amount: Money;
  readonly createdAt: string;
};

type ChargeFacts = {
  readonly id: string;
  readonly amount: Money;
  readonly reference: string;
  readonly createdAt: string;
};

/**
 * A charge is in exactly one of these states, and each state carries only its own data
 * (the failure code, the refund). No combination of flags can contradict another.
 */
export type Charge =
  | (ChargeFacts & { readonly status: 'processing' })
  | (ChargeFacts & { readonly status: 'succeeded' })
  | (ChargeFacts & { readonly status: 'failed'; readonly failureCode: FailureCode })
  | (ChargeFacts & { readonly status: 'refunded'; readonly refund: Refund });

/** What can happen to a charge after it is created. */
export type ChargeEvent =
  | { readonly type: 'succeeded' }
  | { readonly type: 'failed'; readonly failureCode: FailureCode }
  | { readonly type: 'refund-requested'; readonly refund: Refund }
  | { readonly type: 'refund-succeeded' };

/**
 * The whole lifecycle, in one place:
 *
 *   processing  ->  succeeded or failed
 *   succeeded   ->  refunded, with the refund processing
 *   refunded    ->  refunded, with the refund succeeded
 *
 * Anything else is refused with TransitionNotAllowed.
 */
export function transition(charge: Charge, event: ChargeEvent): Charge {
  switch (charge.status) {
    case 'processing':
      if (event.type === 'succeeded') {
        return { ...charge, status: 'succeeded' };
      }
      if (event.type === 'failed') {
        return { ...charge, status: 'failed', failureCode: event.failureCode };
      }
      break;
    case 'succeeded':
      if (event.type === 'refund-requested') {
        return { ...charge, status: 'refunded', refund: event.refund };
      }
      break;
    case 'refunded':
      if (event.type === 'refund-succeeded' && charge.refund.status === 'processing') {
        return { ...charge, refund: { ...charge.refund, status: 'succeeded' } };
      }
      break;
    case 'failed':
      break;
  }
  throw new TransitionNotAllowed(charge, event);
}

export function sameMoney(a: Money, b: Money): boolean {
  return a.value === b.value && a.currency === b.currency;
}

const WHAT_IT_CANNOT_DO = {
  succeeded: 'succeed',
  failed: 'fail',
  'refund-requested': 'be refunded',
  'refund-succeeded': 'complete a refund',
} as const satisfies Record<ChargeEvent['type'], string>;

export class TransitionNotAllowed extends DomainError {
  readonly category = 'conflict';

  constructor(charge: Charge, event: ChargeEvent) {
    super(`Charge ${charge.id} is ${charge.status} and cannot ${WHAT_IT_CANNOT_DO[event.type]}.`);
  }
}

export class ChargeNotFound extends DomainError {
  readonly category = 'not_found';

  constructor(id: string) {
    super(`Charge ${id} does not exist.`);
  }
}

export class PartialRefundNotSupported extends DomainError {
  readonly category = 'invalid_input';

  constructor({ id, amount }: Charge) {
    super(
      `Only full refunds are supported: refund ${amount.value} ${amount.currency} for charge ${id}.`,
    );
  }
}
