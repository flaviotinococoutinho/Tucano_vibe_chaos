import { DomainError } from '../platform/domain-error.ts';

/** The carriers Tucano works with: its own fleet, and the partners it hires. */
export type CarrierCode = 'tucano-express' | 'ligeirinho' | 'correio-nacional' | 'carga-pesada';

/** Only the own fleet skips the sorting hubs: it delivers inside the state of its center. */
export const OWN_FLEET: CarrierCode = 'tucano-express';

/** Why a visit to the address failed. */
export type DeliveryFailureReason = 'recipient_absent' | 'address_not_found' | 'recipient_refused';

/** A visit to the address: at most three per pickup. */
export type Attempt = 1 | 2 | 3;

export type PickupOrigin = { readonly center: string; readonly state: string };
export type PickupDestination = {
  readonly city: string;
  readonly state: string;
  readonly postalCode: string;
};

type PickupFacts = {
  readonly id: string;
  readonly carrier: CarrierCode;
  readonly reference: string;
  readonly trackingCode: string;
  readonly origin: PickupOrigin;
  readonly destination: PickupDestination;
  readonly createdAt: string;
};

/**
 * A pickup is in exactly one of these states, and each state carries only its own data (the
 * failure reason). No combination of flags can contradict another.
 */
export type Pickup =
  | (PickupFacts & { readonly status: 'scheduled'; readonly attempts: 0 })
  | (PickupFacts & { readonly status: 'picked_up'; readonly attempts: 0 })
  | (PickupFacts & { readonly status: 'in_transit'; readonly attempts: 0 })
  | (PickupFacts & { readonly status: 'out_for_delivery'; readonly attempts: Attempt })
  | (PickupFacts & { readonly status: 'delivered'; readonly attempts: Attempt })
  | (PickupFacts & {
      readonly status: 'delivery_failed';
      readonly attempts: Attempt;
      readonly reason: DeliveryFailureReason;
    })
  | (PickupFacts & { readonly status: 'returning'; readonly attempts: Attempt })
  | (PickupFacts & { readonly status: 'returned'; readonly attempts: Attempt });

/** What the journey does to a pickup. Picking the reason and the receiver is chaos, not this. */
export type PickupEvent =
  | { readonly type: 'picked-up' }
  | { readonly type: 'hub-scanned' }
  | { readonly type: 'out-for-delivery' }
  | { readonly type: 'delivered' }
  | { readonly type: 'delivery-failed'; readonly reason: DeliveryFailureReason }
  | { readonly type: 'returning' }
  | { readonly type: 'returned' };

/** The 7 fields of the contract's `Pickup`: the journey's own bookkeeping stays internal. */
export type PickupResponse = {
  readonly id: string;
  readonly carrier: CarrierCode;
  readonly reference: string;
  readonly trackingCode: string;
  readonly status: Pickup['status'];
  readonly attempts: number;
  readonly createdAt: string;
};

export function pickupResponse(pickup: Pickup): PickupResponse {
  const { id, carrier, reference, trackingCode, status, attempts, createdAt } = pickup;

  return { id, carrier, reference, trackingCode, status, attempts, createdAt };
}

/**
 * The whole journey, in one place:
 *
 *   scheduled          -> picked_up
 *   picked_up          -> in_transit (partners) or out_for_delivery (own fleet)
 *   in_transit         -> in_transit (the second hub) or out_for_delivery
 *   out_for_delivery   -> delivered or delivery_failed
 *   delivery_failed    -> out_for_delivery (another visit) or returning
 *   returning          -> returned
 *
 * Anything else is refused with TransitionNotAllowed.
 */
export function transition(pickup: Pickup, event: PickupEvent): Pickup {
  // Built from the facts alone, never from `pickup` itself: a state built by spreading the
  // one before it could carry a stale field along, like a delivery_failed's reason showing
  // up on the next out_for_delivery.
  const facts = factsOf(pickup);
  switch (pickup.status) {
    case 'scheduled':
      if (event.type === 'picked-up') {
        return { ...facts, status: 'picked_up', attempts: 0 };
      }
      break;
    case 'picked_up':
    case 'in_transit':
      if (event.type === 'hub-scanned') {
        return { ...facts, status: 'in_transit', attempts: 0 };
      }
      if (event.type === 'out-for-delivery') {
        return { ...facts, status: 'out_for_delivery', attempts: nextAttempt(pickup.attempts) };
      }
      break;
    case 'out_for_delivery':
      if (event.type === 'delivered') {
        return { ...facts, status: 'delivered', attempts: pickup.attempts };
      }
      if (event.type === 'delivery-failed') {
        return {
          ...facts,
          status: 'delivery_failed',
          attempts: pickup.attempts,
          reason: event.reason,
        };
      }
      break;
    case 'delivery_failed':
      if (event.type === 'out-for-delivery') {
        return { ...facts, status: 'out_for_delivery', attempts: nextAttempt(pickup.attempts) };
      }
      if (event.type === 'returning') {
        return { ...facts, status: 'returning', attempts: pickup.attempts };
      }
      break;
    case 'returning':
      if (event.type === 'returned') {
        return { ...facts, status: 'returned', attempts: pickup.attempts };
      }
      break;
    case 'delivered':
    case 'returned':
      break;
  }
  throw new TransitionNotAllowed(pickup, event);
}

function factsOf(pickup: Pickup): PickupFacts {
  const { id, carrier, reference, trackingCode, origin, destination, createdAt } = pickup;

  return { id, carrier, reference, trackingCode, origin, destination, createdAt };
}

/** Only called from 0, 1 or 2: nothing retries after the third visit. */
function nextAttempt(attempts: 0 | Attempt): Attempt {
  return (attempts + 1) as Attempt;
}

const WHAT_IT_CANNOT_DO = {
  'picked-up': 'be picked up',
  'hub-scanned': 'be scanned at a hub',
  'out-for-delivery': 'go out for delivery',
  delivered: 'be delivered',
  'delivery-failed': 'fail a delivery',
  returning: 'start its way back',
  returned: 'be back at the fulfillment center',
} as const satisfies Record<PickupEvent['type'], string>;

export class TransitionNotAllowed extends DomainError {
  readonly category = 'conflict';

  constructor(pickup: Pickup, event: PickupEvent) {
    super(`Pickup ${pickup.id} is ${pickup.status} and cannot ${WHAT_IT_CANNOT_DO[event.type]}.`);
  }
}

export class PickupNotFound extends DomainError {
  readonly category = 'not_found';

  constructor(id: string) {
    super(`Pickup ${id} does not exist.`);
  }
}
