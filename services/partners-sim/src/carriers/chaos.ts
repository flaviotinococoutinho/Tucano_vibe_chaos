import type { FastifyBaseLogger } from 'fastify';
import type { Random } from '../chance.ts';
import { DomainError } from '../platform/domain-error.ts';
import {
  CALM_WEBHOOKS,
  planWebhook as planWebhookDelivery,
  type WebhookPlan,
} from '../webhooks/chaos-plan.ts';
import type { DeliveryFailureReason } from './pickup.ts';

export type ChaosSettings = {
  /** Share of visits that fail with the recipient absent or the address not found. */
  readonly failureRate: number;
  /** Share of visits where the recipient refuses the parcels. Adds up with `failureRate` to at most 1. */
  readonly refusalRate: number;
  readonly webhooks: {
    readonly dropRate: number;
    readonly duplicateRate: number;
    readonly delayMs: number;
  };
};

/** No chaos at all: how CarrierFake starts, and what DELETE /_chaos/carriers brings back. */
export const CALM: ChaosSettings = {
  failureRate: 0,
  refusalRate: 0,
  webhooks: CALM_WEBHOOKS,
};

/** What happens when the courier visits the address. */
export type VisitOutcome = 'delivered' | DeliveryFailureReason;

/**
 * The knobs, and the dice behind them. Every decision that changes what a client sees is
 * logged at info with the pickup id, so an experiment can be followed in the logs.
 */
export class Chaos {
  private current = CALM;
  private readonly random: Random;

  constructor(random: Random) {
    this.random = random;
  }

  get settings(): ChaosSettings {
    return this.current;
  }

  /** Kept in the order of CALM, so every answer lists the knobs the same way. */
  change(settings: ChaosSettings): void {
    const next: ChaosSettings = {
      ...CALM,
      ...settings,
      webhooks: { ...CALM.webhooks, ...settings.webhooks },
    };
    if (next.failureRate + next.refusalRate > 1) {
      throw new ChaosRatesTooHigh(next.failureRate, next.refusalRate);
    }
    this.current = next;
  }

  reset(): void {
    this.current = CALM;
  }

  /**
   * One roll decides the whole visit: below `refusalRate` it is a refusal; the next slice,
   * as wide as `failureRate` and split evenly in two, is a failure (absence or a bad
   * address); the rest of the time the parcels are delivered.
   */
  visitOutcome(log: FastifyBaseLogger, pickupId: string, attempt: number): VisitOutcome {
    const { refusalRate, failureRate } = this.current;
    const roll = this.random();
    if (roll < refusalRate) {
      log.info({ chaos: 'refusal', pickupId, attempt }, 'chaos: the recipient refuses the parcels');
      return 'recipient_refused';
    }
    if (roll < refusalRate + failureRate) {
      const reason: DeliveryFailureReason =
        roll < refusalRate + failureRate / 2 ? 'recipient_absent' : 'address_not_found';
      log.info({ chaos: 'failure', pickupId, attempt, reason }, 'chaos: the visit fails');
      return reason;
    }

    return 'delivered';
  }

  planWebhook(log: FastifyBaseLogger, pickupId: string, eventId: string): WebhookPlan {
    return planWebhookDelivery(this.random, this.current.webhooks, log, { pickupId, eventId });
  }
}

export class ChaosRatesTooHigh extends DomainError {
  readonly category = 'invalid_input';

  constructor(failureRate: number, refusalRate: number) {
    super(
      `failureRate and refusalRate must add up to at most 1, got ${failureRate} and ${refusalRate}.`,
    );
  }
}
