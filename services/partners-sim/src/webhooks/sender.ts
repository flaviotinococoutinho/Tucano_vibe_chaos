import type { FastifyBaseLogger } from 'fastify';
import type { Clock } from '../clock.ts';
import type { WebhookDeliveryConfig } from '../config.ts';
import { CORRELATION_ID_HEADER } from '../platform/correlation-id.ts';
import type { WebhookPlan } from './chaos-plan.ts';
import { sign } from './signature.ts';

export type WebhookEvent<TData = unknown> = {
  readonly id: string;
  readonly type: string;
  readonly createdAt: string;
  readonly data: TData;
};

/** The request that started the work a webhook reports: its logger and its correlation id. */
export type Origin = { readonly log: FastifyBaseLogger; readonly correlationId: string };

export type WebhookTarget = { readonly url: string; readonly secret: string };

export type Delivery = { readonly outcome: 'delivered' | 'abandoned'; readonly attempts: number };

/** Decides the fate of an event before it is sent: the chaos of the simulator it belongs to. */
export type WebhookChaosPlanner<TData> = (
  log: FastifyBaseLogger,
  event: WebhookEvent<TData>,
) => WebhookPlan;

export type WebhooksOptions<TData> = {
  readonly target: WebhookTarget;
  /** The header a webhook is signed under, so each simulator carries its own name. */
  readonly signatureHeader: string;
  /** The retries and the timeout of each attempt. */
  readonly delivery: WebhookDeliveryConfig;
  readonly clock: Clock;
  readonly plan: WebhookChaosPlanner<TData>;
  /** Aborts when the server shuts down, which stops waits and requests in flight. */
  readonly signal: AbortSignal;
};

/**
 * Delivers events at least once, signed with the shared secret, retried with backoff. Generic
 * over the event data, so every simulator shares it and only its own event shape, header name
 * and chaos plan differ.
 */
export class Webhooks<TData = unknown> {
  private readonly target: WebhookTarget;
  private readonly signatureHeader: string;
  private readonly delivery: WebhookDeliveryConfig;
  private readonly clock: Clock;
  private readonly plan: WebhookChaosPlanner<TData>;
  private readonly signal: AbortSignal;

  constructor({ target, signatureHeader, delivery, clock, plan, signal }: WebhooksOptions<TData>) {
    this.target = target;
    this.signatureHeader = signatureHeader;
    this.delivery = delivery;
    this.clock = clock;
    this.plan = plan;
    this.signal = signal;
  }

  /** Sends one event as the chaos plan says: maybe late, maybe twice, maybe never. */
  async publish(event: WebhookEvent<TData>, origin: Origin): Promise<readonly Delivery[]> {
    const plan = this.plan(origin.log, event);
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
  private async deliver(
    event: WebhookEvent<TData>,
    body: string,
    origin: Origin,
  ): Promise<Delivery> {
    const context = { eventId: event.id, type: event.type };
    for (let attempt = 1; ; attempt += 1) {
      const failure = await this.attempt(body, origin);
      if (failure === undefined) {
        origin.log.info({ ...context, attempt }, 'webhook delivered');
        return { outcome: 'delivered', attempts: attempt };
      }
      const retryInMs = this.delivery.retryDelaysMs[attempt - 1];
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
          [this.signatureHeader]: sign(this.target.secret, body, timestamp),
          [CORRELATION_ID_HEADER]: origin.correlationId,
        },
        body,
        // A redirect is not an acknowledgement: it counts as a failure, like at real PSPs.
        redirect: 'manual',
        signal: AbortSignal.any([this.signal, AbortSignal.timeout(this.delivery.attemptTimeoutMs)]),
      });
      await response.body?.cancel();

      return response.ok ? undefined : `HTTP ${response.status}`;
    } catch (error) {
      if (this.signal.aborted) {
        throw error;
      }
      return reasonOf(error, this.delivery.attemptTimeoutMs);
    }
  }
}

function reasonOf(error: unknown, attemptTimeoutMs: number): string {
  if (!(error instanceof Error)) {
    return String(error);
  }
  if (error.name === 'TimeoutError') {
    return `no answer within ${attemptTimeoutMs} ms`;
  }
  // fetch reports every network error as "fetch failed" and puts the real one in the cause.
  return error.cause instanceof Error ? error.cause.message : error.message;
}
