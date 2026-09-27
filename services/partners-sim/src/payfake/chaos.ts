import type { FastifyBaseLogger } from 'fastify';
import { between, happens, type Random } from '../chance.ts';
import {
  CALM_WEBHOOKS,
  planWebhook as planWebhookDelivery,
  type WebhookPlan,
} from '../webhooks/chaos-plan.ts';

export type ChaosSettings = {
  readonly latencyMs: { readonly min: number; readonly max: number };
  /** Share of charge creations that fail with HTTP 500. Replays never do. */
  readonly errorRate: number;
  /** Share of answers to charge creation, replays included, held until the client gives up. */
  readonly timeoutRate: number;
  /** Share of charges declined at settlement, on top of the magic tokens. */
  readonly declineRate: number;
  readonly webhooks: {
    readonly dropRate: number;
    readonly duplicateRate: number;
    readonly delayMs: number;
  };
};

/** No chaos at all: how PayFake starts, and what DELETE /_chaos/payfake brings back. */
export const CALM: ChaosSettings = {
  latencyMs: { min: 0, max: 0 },
  errorRate: 0,
  timeoutRate: 0,
  declineRate: 0,
  webhooks: CALM_WEBHOOKS,
};

type Decision = Record<string, unknown>;

/**
 * The knobs, and the dice behind them. Every decision that changes what a client sees is
 * logged at info with the charge id, so an experiment can be followed in the logs.
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
    this.current = {
      ...CALM,
      ...settings,
      latencyMs: { ...CALM.latencyMs, ...settings.latencyMs },
      webhooks: { ...CALM.webhooks, ...settings.webhooks },
    };
  }

  reset(): void {
    this.current = CALM;
  }

  /** How long to hold a ready answer; 0 when latency is off. */
  latency(log: FastifyBaseLogger, chargeId: string): number {
    const { min, max } = this.current.latencyMs;
    const delayMs = max === 0 ? 0 : between(this.random, min, max);
    if (delayMs > 0) {
      log.info({ chaos: 'latency', chargeId, delayMs }, 'chaos: delaying the response');
    }

    return delayMs;
  }

  /** The charge does not exist yet when this is decided, so the log names its reference. */
  failsCharge(log: FastifyBaseLogger, reference: string): boolean {
    const decision = { chaos: 'error', reference };

    return this.roll(log, this.current.errorRate, decision, 'failing with 500');
  }

  holdsResponse(log: FastifyBaseLogger, chargeId: string): boolean {
    const decision = { chaos: 'timeout', chargeId };

    return this.roll(log, this.current.timeoutRate, decision, 'holding the response');
  }

  declines(log: FastifyBaseLogger, chargeId: string): boolean {
    const decision = { chaos: 'decline', chargeId };

    return this.roll(log, this.current.declineRate, decision, 'declining the charge');
  }

  planWebhook(log: FastifyBaseLogger, chargeId: string, eventId: string): WebhookPlan {
    return planWebhookDelivery(this.random, this.current.webhooks, log, { chargeId, eventId });
  }

  private roll(log: FastifyBaseLogger, rate: number, decision: Decision, what: string): boolean {
    const strikes = happens(this.random, rate);
    if (strikes) {
      log.info(decision, `chaos: ${what}`);
    }

    return strikes;
  }
}

/** The 500 of the error rate. It hits before the charge exists, so a retry is safe. */
export class InjectedFailure extends Error {
  constructor() {
    super('PayFake failed on purpose: the chaos error rate hit this charge.');
    this.name = 'InjectedFailure';
  }
}
