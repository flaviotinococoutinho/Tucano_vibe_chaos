import { between, type Random } from '../chance.ts';
import type { Clock } from '../clock.ts';
import type { CourierDevice } from '../couriers/device.ts';
import { ExpiringMap, type Retention } from '../expiring-map.ts';
import { newId } from '../ids.ts';
import type { Origin, Webhooks } from '../webhooks/sender.ts';
import type { Chaos } from './chaos.ts';
import { hubFor } from './hubs.ts';
import {
  type CarrierCode,
  type DeliveryFailureReason,
  OWN_FLEET,
  type Pickup,
  type PickupDestination,
  type PickupEvent,
  PickupNotFound,
  type PickupOrigin,
  transition,
} from './pickup.ts';
import { randomReceiverDocument, randomReceiverName } from './receiver.ts';

export type PickupRequest = {
  readonly carrier: CarrierCode;
  readonly reference: string;
  readonly trackingCode: string;
  readonly origin: PickupOrigin;
  readonly destination: PickupDestination;
  readonly parcels: number;
  readonly weightGrams: number;
};

export type ParcelEventType =
  | 'parcel.picked_up'
  | 'parcel.hub_scanned'
  | 'parcel.out_for_delivery'
  | 'parcel.delivered'
  | 'parcel.delivery_failed'
  | 'parcel.returning'
  | 'parcel.returned';

/** What a CarrierFake webhook carries; `Webhooks` only needs to know its `data` shape. */
export type ParcelEventData = {
  readonly pickupId: string;
  readonly carrier: CarrierCode;
  readonly reference: string;
  readonly trackingCode: string;
  /** Only in `parcel.hub_scanned`. */
  readonly hub?: string;
  /** In `parcel.out_for_delivery`, `parcel.delivered` and `parcel.delivery_failed`. */
  readonly attempt?: number;
  /** Only in `parcel.delivered`. */
  readonly receiverName?: string;
  /** Only in `parcel.delivered`. */
  readonly receiverDocument?: string;
  /** Only in `parcel.delivery_failed`. */
  readonly reason?: DeliveryFailureReason;
};

export type ParcelEvent = {
  readonly id: string;
  readonly type: ParcelEventType;
  readonly createdAt: string;
  readonly data: ParcelEventData;
};

type Range = { readonly min: number; readonly max: number };

export type CarriersOptions = {
  readonly clock: Clock;
  readonly random: Random;
  readonly chaos: Chaos;
  readonly webhooks: Webhooks<ParcelEventData>;
  /** The own fleet's device: reports positions while a courier rides out for delivery. */
  readonly device?: CourierDevice;
  /** How long a step of the journey takes before its webhook goes out. */
  readonly stepDelayMs: Range;
  /** How long CarrierFake remembers pickups and their history, and how many at most. */
  readonly retention: Retention;
  /** Aborts when the server shuts down, which cancels the journeys still walking. */
  readonly signal: AbortSignal;
};

/**
 * Every carrier Tucano works with, playing the same part: a pickup walks its journey on its
 * own clock, one step at a time, each one reported to logistics by webhook.
 */
export class Carriers {
  private readonly pickups: ExpiringMap<Pickup>;
  /** Every event of each pickup, webhook delivered or not: what a merchant reconciles against. */
  private readonly eventsByPickup: ExpiringMap<readonly ParcelEvent[]>;
  /** The merchant's reference of each pickup, for a merchant that lost the answer to its POST. */
  private readonly pickupIdsByReference: ExpiringMap<string>;
  private readonly clock: Clock;
  private readonly random: Random;
  private readonly chaos: Chaos;
  private readonly webhooks: Webhooks<ParcelEventData>;
  private readonly device: CourierDevice | undefined;
  private readonly stepDelayMs: Range;
  private readonly signal: AbortSignal;

  constructor({
    clock,
    random,
    chaos,
    webhooks,
    device,
    stepDelayMs,
    retention,
    signal,
  }: CarriersOptions) {
    this.pickups = new ExpiringMap(clock, retention);
    this.eventsByPickup = new ExpiringMap(clock, retention);
    this.pickupIdsByReference = new ExpiringMap(clock, retention);
    this.clock = clock;
    this.random = random;
    this.chaos = chaos;
    this.webhooks = webhooks;
    this.device = device;
    this.stepDelayMs = stepDelayMs;
    this.signal = signal;
  }

  bookPickup(request: PickupRequest, origin: Origin): Pickup {
    const pickup: Pickup = {
      id: newId('pk'),
      carrier: request.carrier,
      reference: request.reference,
      trackingCode: request.trackingCode,
      origin: request.origin,
      destination: request.destination,
      status: 'scheduled',
      attempts: 0,
      createdAt: this.timestamp(),
    };
    this.pickups.set(pickup.id, pickup);
    this.eventsByPickup.set(pickup.id, []);
    this.pickupIdsByReference.set(request.reference, pickup.id);
    origin.log.info(
      { pickupId: pickup.id, reference: request.reference, carrier: pickup.carrier },
      'pickup booked',
    );
    this.walk(pickup.id, origin).catch((error: unknown) => {
      if (!this.signal.aborted) {
        origin.log.error({ err: error, pickupId: pickup.id }, 'journey failed');
      }
    });

    return pickup;
  }

  pickup(id: string): Pickup {
    const pickup = this.pickups.get(id);
    if (pickup === undefined) {
      throw new PickupNotFound(id);
    }

    return pickup;
  }

  /**
   * The events of a pickup so far, oldest first, whatever happened to their webhooks: a
   * dropped webhook loses the message, never the event.
   */
  events(id: string): readonly ParcelEvent[] {
    const events = this.eventsByPickup.get(id);
    if (events === undefined) {
      throw new PickupNotFound(id);
    }

    return events;
  }

  /** The pickup made for a merchant reference, if there is one. */
  pickupFor(reference: string): Pickup | undefined {
    const id = this.pickupIdsByReference.get(reference);

    return id === undefined ? undefined : this.pickups.get(id);
  }

  /**
   * Walks a pickup through its journey, one step at a time: a delay, a transition, a webhook
   * awaited before the next step starts. Own fleet skips the hubs; a failed visit that is not
   * a refusal is tried again, up to three visits, before the parcels return.
   */
  private async walk(id: string, origin: Origin): Promise<void> {
    const pickedUp = await this.advance(
      id,
      { type: 'picked-up' },
      'parcel.picked_up',
      origin,
      () => ({}),
    );
    if (pickedUp === undefined) {
      return;
    }

    if (pickedUp.carrier !== OWN_FLEET) {
      const atOriginHub = await this.advance(
        id,
        { type: 'hub-scanned' },
        'parcel.hub_scanned',
        origin,
        (p) => ({ hub: hubFor(p.origin.state) }),
      );
      if (atOriginHub === undefined) {
        return;
      }

      if (atOriginHub.destination.state !== atOriginHub.origin.state) {
        const atDestinationHub = await this.advance(
          id,
          { type: 'hub-scanned' },
          'parcel.hub_scanned',
          origin,
          (p) => ({ hub: hubFor(p.destination.state) }),
        );
        if (atDestinationHub === undefined) {
          return;
        }
      }
    }

    for (;;) {
      const dispatched = await this.advance(
        id,
        { type: 'out-for-delivery' },
        'parcel.out_for_delivery',
        origin,
        (p) => ({ attempt: p.attempts }),
      );
      if (dispatched === undefined) {
        return;
      }
      const attempt = dispatched.attempts;

      // The own fleet's device rides from the center to the door while the visit happens; a
      // partner has no device at all, so this call is the only place that carrier is checked.
      if (dispatched.carrier === OWN_FLEET) {
        await this.device?.ride(dispatched, origin);
      }

      // The outcome is decided now; `advance` still spends the one delay of this step
      // before it turns into a delivered or a delivery_failed webhook.
      const outcome = this.chaos.visitOutcome(origin.log, id, attempt);
      if (outcome === 'delivered') {
        await this.advance(id, { type: 'delivered' }, 'parcel.delivered', origin, () => ({
          attempt,
          receiverName: randomReceiverName(this.random),
          receiverDocument: randomReceiverDocument(this.random),
        }));
        if (dispatched.carrier === OWN_FLEET) {
          await this.device?.end(dispatched, 'delivered', origin);
        }
        return;
      }

      const failed = await this.advance(
        id,
        { type: 'delivery-failed', reason: outcome },
        'parcel.delivery_failed',
        origin,
        () => ({ attempt, reason: outcome }),
      );
      if (failed === undefined) {
        return;
      }
      if (dispatched.carrier === OWN_FLEET) {
        await this.device?.end(dispatched, 'delivery_failed', origin);
      }
      if (outcome === 'recipient_refused' || attempt >= 3) {
        break;
      }
    }

    const returning = await this.advance(
      id,
      { type: 'returning' },
      'parcel.returning',
      origin,
      () => ({}),
    );
    if (returning === undefined) {
      return;
    }
    await this.advance(id, { type: 'returned' }, 'parcel.returned', origin, () => ({}));
  }

  /** One step: wait, transition, log, then await the webhook before the caller moves on. */
  private async advance(
    id: string,
    event: PickupEvent,
    type: ParcelEventType,
    origin: Origin,
    extra: (pickup: Pickup) => Partial<ParcelEventData>,
  ): Promise<Pickup | undefined> {
    await this.sleepStep();
    const before = this.pickups.get(id);
    if (before === undefined) {
      return undefined;
    }
    const after = transition(before, event);
    this.pickups.set(id, after);
    origin.log.info(
      { pickupId: id, type, status: after.status, attempts: after.attempts },
      'pickup event',
    );

    const parcelEvent: ParcelEvent = {
      id: newId('evt'),
      type,
      createdAt: this.timestamp(),
      data: {
        pickupId: after.id,
        carrier: after.carrier,
        reference: after.reference,
        trackingCode: after.trackingCode,
        ...extra(after),
      },
    };
    this.eventsByPickup.set(id, [...(this.eventsByPickup.get(id) ?? []), parcelEvent]);
    // The next step waits for this one: a drop counts as done, same as a delivered webhook.
    await this.webhooks.publish(parcelEvent, origin);

    return after;
  }

  private async sleepStep(): Promise<void> {
    const { min, max } = this.stepDelayMs;
    await this.clock.sleep(between(this.random, min, max), this.signal);
  }

  private timestamp(): string {
    return new Date(this.clock.now()).toISOString();
  }
}
