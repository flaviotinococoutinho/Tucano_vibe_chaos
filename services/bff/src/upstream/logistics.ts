import { call, type Trace, type Upstream } from './http.ts';

const SHIPMENT_STATUSES = [
  'created',
  'ready_for_pickup',
  'picked_up',
  'in_transit',
  'out_for_delivery',
  'delivery_failed',
  'returning',
  'returned',
  'delivered',
  'cancelled',
] as const;

export type ShipmentStatus = (typeof SHIPMENT_STATUSES)[number];

const DELIVERY_FAILURES = ['recipient_absent', 'address_not_found', 'recipient_refused'] as const;

export type DeliveryFailure = (typeof DELIVERY_FAILURES)[number];

/** One step of the journey. What a step does not have is null, never missing. */
export type TrackingStep = {
  readonly status: ShipmentStatus;
  readonly at: string;
  /** The hub that scanned the parcels, on the way. */
  readonly hub: string | null;
  /** The visit to the address, 1 to 3. */
  readonly attempt: number | null;
  /** Why a visit did not deliver. */
  readonly reason: DeliveryFailure | null;
};

export type Tracking = {
  readonly trackingCode: string;
  readonly status: ShipmentStatus;
  readonly carrier: string;
  readonly destination: { readonly municipality: string; readonly state: string };
  readonly updatedAt: string;
  /** Oldest first. */
  readonly steps: readonly TrackingStep[];
};

/** The logistics service (Laravel): the public tracking page of a shipment (UC-SHP-10). */
export type Logistics = {
  /** The page, or null while no news of the code reached it. */
  tracking(code: string, trace: Trace): Promise<Tracking | null>;
};

export function logisticsAt(upstream: Upstream): Logistics {
  return {
    async tracking(code, trace) {
      const path = `/v1/tracking/${encodeURIComponent(code)}`;
      const answer = await call(upstream, { method: 'GET', path }, trace);
      if (answer.status === 404) {
        return null;
      }
      if (answer.status !== 200) {
        throw answer.unexpected();
      }
      const fields = answer.fields();
      const destination = fields.object('destination');

      return {
        trackingCode: fields.text('trackingCode'),
        status: fields.oneOf('status', SHIPMENT_STATUSES),
        carrier: fields.text('carrier'),
        destination: {
          municipality: destination.text('municipality'),
          state: destination.text('state'),
        },
        updatedAt: fields.instant('updatedAt'),
        steps: fields.objects('steps').map((step) => ({
          status: step.oneOf('status', SHIPMENT_STATUSES),
          at: step.instant('at'),
          hub: step.optionalText('hub'),
          attempt: step.optionalInteger('attempt'),
          reason: step.optionalOneOf('reason', DELIVERY_FAILURES),
        })),
      };
    },
  };
}
