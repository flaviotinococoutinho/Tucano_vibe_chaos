import { between, type Random } from '../chance.ts';
import type { Clock } from '../clock.ts';
import { CORRELATION_ID_HEADER } from '../platform/correlation-id.ts';
import { type Origin, reasonOf } from '../webhooks/sender.ts';
import { sign } from '../webhooks/signature.ts';
import { destinationPoint, type LatLng, pointAt, remainingMetersAt, routeFor } from './route.ts';

/** The header the device signs its reports under: the same scheme as the carrier webhooks. */
const SIGNATURE_HEADER = 'Courier-Signature';
/** One try only, never retried: a lost report is replaced by the next position, not resent. */
const REPORT_TIMEOUT_MS = 1_000;
const JITTER_MIN_METERS = 1;
const JITTER_MAX_METERS = 5;

/** What `ride` and `end` need of a pickup: the own fleet's own small slice of it. */
export type CourierPickup = {
  readonly id: string;
  readonly trackingCode: string;
  readonly origin: { readonly center: string };
};

/** The visit's outcome, as the delivery news contract spells it: no failure reason travels here. */
export type DeliveryOutcome = 'delivered' | 'delivery_failed';

export type CourierDeviceOptions = {
  readonly url: string;
  readonly secret: string;
  readonly positionIntervalMs: number;
  readonly rideMs: number;
  readonly clock: Clock;
  readonly random: Random;
  /** Aborts when the server shuts down: the same signal the journeys already carry. */
  readonly signal: AbortSignal;
};

type DeliveryNews =
  | {
      readonly type: 'position';
      readonly trackingCode: string;
      readonly latitude: number;
      readonly longitude: number;
      readonly at: string;
      readonly remainingMeters: number;
    }
  | {
      readonly type: 'ended';
      readonly trackingCode: string;
      readonly outcome: DeliveryOutcome;
      readonly at: string;
    };

/**
 * The own fleet's courier device: rides a pickup's route from its fulfillment center to the
 * door, one `position` every interval, then one `ended` once the visit is over. `Carriers.walk`
 * is the only caller, and only for `tucano-express`: a partner pickup never reaches it.
 */
export class CourierDevice {
  private readonly url: string;
  private readonly secret: string;
  private readonly positionIntervalMs: number;
  private readonly rideMs: number;
  private readonly clock: Clock;
  private readonly random: Random;
  private readonly signal: AbortSignal;
  /** Which pickups already had a report fail once: the rest of that ride logs at debug. */
  private readonly warnedPickupIds = new Set<string>();

  constructor({
    url,
    secret,
    positionIntervalMs,
    rideMs,
    clock,
    random,
    signal,
  }: CourierDeviceOptions) {
    this.url = url;
    this.secret = secret;
    this.positionIntervalMs = positionIntervalMs;
    this.rideMs = rideMs;
    this.clock = clock;
    this.random = random;
    this.signal = signal;
  }

  /**
   * Moves the courier from the fulfillment center to the door over `rideMs`, one report every
   * `positionIntervalMs`. Ticks are spaced evenly across the ride, so the last one always lands
   * exactly on the door, even when `rideMs` is not a whole multiple of the interval.
   */
  async ride(pickup: CourierPickup, origin: Origin): Promise<void> {
    this.warnedPickupIds.delete(pickup.id);
    const route = routeFor(pickup.origin.center, pickup.trackingCode);
    const ticks = Math.max(1, Math.round(this.rideMs / this.positionIntervalMs));
    const tickMs = this.rideMs / ticks;
    for (let tick = 1; tick <= ticks; tick += 1) {
      await this.clock.sleep(tickMs, this.signal);
      const t = tick / ticks;
      const point = jitter(this.random, pointAt(route, t));
      await this.report(pickup.id, origin, {
        type: 'position',
        trackingCode: pickup.trackingCode,
        latitude: round6(point.latitude),
        longitude: round6(point.longitude),
        at: this.timestamp(),
        remainingMeters: remainingMetersAt(route, t),
      });
    }
  }

  /** The visit is over: called right after its own delivered or delivery_failed webhook. */
  async end(pickup: CourierPickup, outcome: DeliveryOutcome, origin: Origin): Promise<void> {
    await this.report(pickup.id, origin, {
      type: 'ended',
      trackingCode: pickup.trackingCode,
      outcome,
      at: this.timestamp(),
    });
    this.warnedPickupIds.delete(pickup.id);
  }

  /** One try, timed out fast, never retried: a failure here must never hold the journey back. */
  private async report(pickupId: string, origin: Origin, news: DeliveryNews): Promise<void> {
    const body = JSON.stringify(news);
    const timestamp = Math.floor(this.clock.now() / 1000);
    try {
      const response = await fetch(`${this.url}/v1/positions`, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          [SIGNATURE_HEADER]: sign(this.secret, body, timestamp),
          [CORRELATION_ID_HEADER]: origin.correlationId,
        },
        body,
        signal: AbortSignal.any([this.signal, AbortSignal.timeout(REPORT_TIMEOUT_MS)]),
      });
      await response.body?.cancel();
      if (!response.ok) {
        this.logFailure(pickupId, origin, news.type, `HTTP ${response.status}`);
      }
    } catch (error) {
      // Shutdown is not a report failure: the journey itself is already stopping.
      if (!this.signal.aborted) {
        this.logFailure(pickupId, origin, news.type, reasonOf(error, REPORT_TIMEOUT_MS));
      }
    }
  }

  private logFailure(pickupId: string, origin: Origin, type: string, failure: string): void {
    const context = { pickupId, type, failure };
    if (this.warnedPickupIds.has(pickupId)) {
      origin.log.debug(context, 'courier report failed');
      return;
    }
    this.warnedPickupIds.add(pickupId);
    origin.log.warn(context, 'courier report failed');
  }

  private timestamp(): string {
    return new Date(this.clock.now()).toISOString();
  }
}

/** A few meters of GPS noise, the same order of magnitude a real phone reports, in a random direction. */
function jitter(random: Random, point: LatLng): LatLng {
  const bearing = between(random, 0, 359);
  const distance = between(random, JITTER_MIN_METERS, JITTER_MAX_METERS);

  return destinationPoint(point, bearing, distance);
}

function round6(value: number): number {
  return Math.round(value * 1_000_000) / 1_000_000;
}
