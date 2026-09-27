import type { FastifyBaseLogger } from 'fastify';
import { happens, type Random } from '../chance.ts';

/** The chaos knobs every simulator's webhooks share: drop, duplicate and delay. */
export type WebhookChaosSettings = {
  readonly dropRate: number;
  readonly duplicateRate: number;
  readonly delayMs: number;
};

/** No webhook chaos: how every simulator starts. */
export const CALM_WEBHOOKS: WebhookChaosSettings = { dropRate: 0, duplicateRate: 0, delayMs: 0 };

export type WebhookPlan =
  | { readonly fate: 'dropped' }
  | { readonly fate: 'sent'; readonly copies: 1 | 2; readonly delayMs: number };

/**
 * Decides the fate of one webhook: dropped, sent once or sent twice, and how long to wait
 * before the first attempt. Every decision that changes what the receiver sees is logged at
 * info, tagged with whatever the caller wants a decision tied to (a charge, a pickup...).
 */
export function planWebhook(
  random: Random,
  settings: WebhookChaosSettings,
  log: FastifyBaseLogger,
  context: Record<string, unknown>,
): WebhookPlan {
  const { dropRate, duplicateRate, delayMs } = settings;
  if (roll(random, log, dropRate, { chaos: 'webhook-drop', ...context }, 'dropping the webhook')) {
    return { fate: 'dropped' };
  }
  const twice = { chaos: 'webhook-duplicate', ...context };
  const copies = roll(random, log, duplicateRate, twice, 'sending the webhook twice') ? 2 : 1;
  if (delayMs > 0) {
    log.info({ chaos: 'webhook-delay', ...context, delayMs }, 'chaos: delaying the webhook');
  }

  return { fate: 'sent', copies, delayMs };
}

function roll(
  random: Random,
  log: FastifyBaseLogger,
  rate: number,
  decision: Record<string, unknown>,
  what: string,
): boolean {
  const strikes = happens(random, rate);
  if (strikes) {
    log.info(decision, `chaos: ${what}`);
  }

  return strikes;
}
