import type { FastifyBaseLogger } from 'fastify';
import type { Clock } from '../clock.ts';
import { CORRELATION_ID_HEADER } from '../platform/correlation-id.ts';
import type { Chaos } from './chaos.ts';
import type { FailureCode, Money } from './charge.ts';
import { SIGNATURE_HEADER, sign } from './signature.ts';

export type WebhookEvent = {
  readonly id: string;
  readonly type: 'charge.succeeded' | 'charge.failed' | 'refund.succeeded';
  readonly createdAt: string;
  readonly data: {
    readonly chargeId: string;
    readonly reference: string;
    readonly amount: Money;
    readonly failureCode?: FailureCode;
  };
};

/** The request that started the work a webhook reports: its logger and its correlation id. */
export type Origin = { readonly log: FastifyBaseLogger; readonly correlationId: string };

type WebhookTarget = { readonly url: string; readonly secret: string };

/** Waits before each retry. After the first attempt fails, five more come within 31 seconds. */
const RETRY_DELAYS_MS = [1_000, 2_000, 4_000, 8_000, 16_000] as const;

const ATTEMPT_TIMEOUT_MS = 5_000;

export type Delivery = { readonly outcome: 'delivered' | 'abandoned'; readonly attempts: number };

export type WebhooksOptions = {
  readonly target: WebhookTarget;
  readonly clock: Clock;
  readonly chaos: Chaos;
  /** Aborts when the server shuts down, which stops waits and requests in flight. */
  readonly signal: AbortSignal;
};

/** Tells the merchant what happened to its charges, at least once, signed with the shared secret. */
export class Webhooks {
  private readonly target: WebhookTarget;
  private readonly clock: Clock;
  private readonly chaos: Chaos;
  private readonly signal: AbortSignal;

  constructor({ target, clock, chaos, signal }: WebhooksOptions) {
    this.target = target;
    this.clock = clock;
    this.chaos = chaos;
    this.signal = signal;
  }

  /** Sends one event as the chaos settings say: maybe late, maybe twice, maybe never. */
  async publish(event: WebhookEvent, origin: Origin): Promise<readonly Delivery[]> {
    const plan = this.chaos.planWebhook(origin.log, event.data.chargeId, event.id);
    if (plan.fate === 'dropped') {
      return [];
    }
    if (plan.delayMs > 0) {
      await this.clock.sleep(plan.delayMs, this.signal);
    }
    // Every copy is the same event, id included: the receiver's inbox has to drop the second one.
    const body = JSON.stringify(event);
    const deliveries: Delivery[] = [];
    for (let copy = 1; copy <= plan.copies; copy += 1) {
      deliveries.push(await this.deliver(event, body, origin));
    }

    return deliveries;
  }

  /** Retries network errors and any answer outside 2xx, then gives up after the last delay. */
  private async deliver(event: WebhookEvent, body: string, origin: Origin): Promise<Delivery> {
    const context = { eventId: event.id, type: event.type, chargeId: event.data.chargeId };
    for (let attempt = 1; ; attempt += 1) {
      const failure = await this.attempt(body, origin);
      if (failure === undefined) {
        origin.log.info({ ...context, attempt }, 'webhook delivered');
        return { outcome: 'delivered', attempts: attempt };
      }
      const retryInMs = RETRY_DELAYS_MS[attempt - 1];
      if (retryInMs === undefined) {
        origin.log.error(
          { ...context, attempt, failure },
          'webhook abandoned after the last retry',
        );
        return { outcome: 'abandoned', attempts: attempt };
      }
      origin.log.warn({ ...context, attempt, failure, retryInMs }, 'webhook delivery failed');
      await this.clock.sleep(retryInMs, this.signal);
    }
  }

  /** Why the attempt failed, or undefined when the receiver took it. */
  private async attempt(body: string, origin: Origin): Promise<string | undefined> {
    // Signed again on every attempt, so a late retry still falls inside the receiver's tolerance.
    const timestamp = Math.floor(this.clock.now() / 1000);
    try {
      const response = await fetch(this.target.url, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          [SIGNATURE_HEADER]: sign(this.target.secret, body, timestamp),
          [CORRELATION_ID_HEADER]: origin.correlationId,
        },
        body,
        // A redirect is not an acknowledgement: it counts as a failure, like at real PSPs.
        redirect: 'manual',
        signal: AbortSignal.any([this.signal, AbortSignal.timeout(ATTEMPT_TIMEOUT_MS)]),
      });
      await response.body?.cancel();

      return response.ok ? undefined : `HTTP ${response.status}`;
    } catch (error) {
      if (this.signal.aborted) {
        throw error;
      }
      return reasonOf(error);
    }
  }
}

function reasonOf(error: unknown): string {
  if (!(error instanceof Error)) {
    return String(error);
  }
  if (error.name === 'TimeoutError') {
    return `no answer within ${ATTEMPT_TIMEOUT_MS} ms`;
  }
  // fetch reports every network error as "fetch failed" and puts the real one in the cause.
  return error.cause instanceof Error ? error.cause.message : error.message;
}
