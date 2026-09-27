import { between, type Random } from '../chance.ts';
import type { Clock } from '../clock.ts';
import { ExpiringMap, type Retention } from '../expiring-map.ts';
import type { Chaos } from './chaos.ts';
import {
  type Charge,
  type ChargeEvent,
  ChargeNotFound,
  type FailureCode,
  type Money,
  PartialRefundNotSupported,
  type Refund,
  sameMoney,
  transition,
} from './charge.ts';
import { newId } from './ids.ts';
import type { Origin, WebhookEvent, Webhooks } from './webhooks.ts';

/** How long PayFake remembers charges and Idempotency-Keys, and how many at most. */
export const RETENTION: Retention = { ttlMs: 24 * 60 * 60 * 1000, maxEntries: 20_000 };

/** Card tokens with a fixed answer, so a test can ask for a refusal. Any other token pays. */
const MAGIC_TOKENS = new Map<string, FailureCode>([
  ['tok_decline', 'card_declined'],
  ['tok_insufficient', 'insufficient_funds'],
]);

export type ChargeRequest = {
  readonly amount: Money;
  readonly cardToken: string;
  readonly reference: string;
};

type Range = { readonly min: number; readonly max: number };

export type PayFakeOptions = {
  readonly clock: Clock;
  readonly random: Random;
  readonly chaos: Chaos;
  readonly webhooks: Webhooks;
  /** How long a charge or a refund stays processing before it settles. */
  readonly processingDelayMs: Range;
  /** Aborts when the server shuts down, which cancels the settlements still waiting. */
  readonly signal: AbortSignal;
};

/**
 * The payment provider. Charges and refunds are accepted as processing, and each one settles
 * after a processing delay and is reported to the merchant by webhook, like at a real PSP.
 */
export class PayFake {
  private readonly charges: ExpiringMap<Charge>;
  private readonly clock: Clock;
  private readonly random: Random;
  private readonly chaos: Chaos;
  private readonly webhooks: Webhooks;
  private readonly processingDelayMs: Range;
  private readonly signal: AbortSignal;

  constructor({ clock, random, chaos, webhooks, processingDelayMs, signal }: PayFakeOptions) {
    this.charges = new ExpiringMap(clock, RETENTION);
    this.clock = clock;
    this.random = random;
    this.chaos = chaos;
    this.webhooks = webhooks;
    this.processingDelayMs = processingDelayMs;
    this.signal = signal;
  }

  createCharge({ amount, cardToken, reference }: ChargeRequest, origin: Origin): Charge {
    const charge: Charge = {
      id: newId('ch'),
      status: 'processing',
      amount,
      reference,
      createdAt: this.timestamp(),
    };
    this.charges.set(charge.id, charge);
    origin.log.info({ chargeId: charge.id, reference }, 'charge created');
    this.afterProcessing(origin, () => this.settleCharge(charge.id, cardToken, origin));

    return charge;
  }

  charge(id: string): Charge {
    const charge = this.charges.get(id);
    if (charge === undefined) {
      throw new ChargeNotFound(id);
    }

    return charge;
  }

  refund(chargeId: string, amount: Money, origin: Origin): Refund {
    const charge = this.charge(chargeId);
    const refund: Refund = {
      id: newId('re'),
      chargeId,
      status: 'processing',
      amount,
      createdAt: this.timestamp(),
    };
    const refunded = transition(charge, { type: 'refund-requested', refund });
    if (!sameMoney(amount, charge.amount)) {
      throw new PartialRefundNotSupported(charge);
    }
    this.charges.set(chargeId, refunded);
    origin.log.info({ chargeId, refundId: refund.id }, 'refund created');
    this.afterProcessing(origin, () => this.settleRefund(chargeId, origin));

    return refund;
  }

  private async settleCharge(id: string, cardToken: string, origin: Origin): Promise<void> {
    const charge = this.charges.get(id);
    if (charge === undefined) {
      return;
    }
    const failureCode =
      MAGIC_TOKENS.get(cardToken) ??
      (this.chaos.declines(origin.log, id) ? 'card_declined' : undefined);
    const settled = this.apply(
      charge,
      failureCode === undefined ? { type: 'succeeded' } : { type: 'failed', failureCode },
    );
    origin.log.info({ chargeId: id, status: settled.status, failureCode }, 'charge settled');

    const type = failureCode === undefined ? 'charge.succeeded' : 'charge.failed';
    const details = { amount: charge.amount, failureCode };
    await this.webhooks.publish(this.webhookEvent(type, charge, details), origin);
  }

  private async settleRefund(chargeId: string, origin: Origin): Promise<void> {
    const charge = this.charges.get(chargeId);
    if (charge?.status !== 'refunded') {
      return;
    }
    this.apply(charge, { type: 'refund-succeeded' });
    origin.log.info({ chargeId, refundId: charge.refund.id }, 'refund settled');

    await this.webhooks.publish(
      this.webhookEvent('refund.succeeded', charge, { amount: charge.refund.amount }),
      origin,
    );
  }

  private apply(charge: Charge, event: ChargeEvent): Charge {
    const next = transition(charge, event);
    this.charges.set(next.id, next);

    return next;
  }

  /** Runs `settle` once the processing delay is over, unless the server shuts down first. */
  private afterProcessing(origin: Origin, settle: () => Promise<void>): void {
    const { min, max } = this.processingDelayMs;
    this.clock
      .sleep(between(this.random, min, max), this.signal)
      .then(settle)
      .catch((error: unknown) => {
        if (!this.signal.aborted) {
          origin.log.error({ err: error }, 'settlement failed');
        }
      });
  }

  private webhookEvent(
    type: WebhookEvent['type'],
    charge: Charge,
    details: Pick<WebhookEvent['data'], 'amount' | 'failureCode'>,
  ): WebhookEvent {
    return {
      id: newId('evt'),
      type,
      createdAt: this.timestamp(),
      data: { chargeId: charge.id, reference: charge.reference, ...details },
    };
  }

  private timestamp(): string {
    return new Date(this.clock.now()).toISOString();
  }
}
