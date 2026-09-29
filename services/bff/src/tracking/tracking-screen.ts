import {
  type Action,
  component,
  type Entity,
  href,
  type Link,
  path,
  rel,
  screen,
  type Tone,
} from '../hypermedia/index.ts';
import type { DeliveryFailure, ShipmentStatus, Tracking, TrackingStep } from '../upstream/index.ts';
import { TYPED_TRACKING_CODE } from './code.ts';

type Look = {
  readonly label: string;
  readonly tone: Tone;
  /** While the parcel moves, the page asks to be fetched again. */
  readonly moving: boolean;
};

/** How each step of the journey reads, and whether the story is still going. */
const JOURNEY: Readonly<Record<ShipmentStatus, Look>> = {
  created: { label: 'Pedido de envio criado', tone: 'waiting', moving: true },
  ready_for_pickup: { label: 'Etiqueta pronta', tone: 'waiting', moving: true },
  picked_up: { label: 'Coletado pela transportadora', tone: 'info', moving: true },
  in_transit: { label: 'Em trânsito', tone: 'info', moving: true },
  out_for_delivery: { label: 'Saiu para entrega', tone: 'info', moving: true },
  delivery_failed: { label: 'Entrega não realizada', tone: 'danger', moving: true },
  returning: { label: 'Voltando ao remetente', tone: 'danger', moving: true },
  returned: { label: 'Devolvido ao remetente', tone: 'neutral', moving: false },
  delivered: { label: 'Entregue', tone: 'success', moving: false },
  cancelled: { label: 'Envio cancelado', tone: 'neutral', moving: false },
};

/** Why a visit did not deliver, as the detail of its step. */
const WHY_NOT_DELIVERED: Readonly<Record<DeliveryFailure, string>> = {
  recipient_absent: 'Ninguém estava em casa.',
  address_not_found: 'O endereço não foi encontrado.',
  recipient_refused: 'O destinatário recusou a entrega.',
};

type Carrier = {
  /** The name people read. */
  readonly name: string;
  /** How a sentence says the parcel travels with it, article included. */
  readonly withIt: string;
};

/**
 * The names people read for the carriers. Logistics keeps them too (CarrierSeeder);
 * a carrier the BFF does not know yet shows its code until it gets a name here.
 */
const CARRIERS: Readonly<Record<string, Carrier>> = {
  'tucano-express': { name: 'Tucano Express', withIt: 'com a Tucano Express' },
  ligeirinho: { name: 'Ligeirinho Transportes', withIt: 'com a Ligeirinho Transportes' },
  'correio-nacional': { name: 'Correio Nacional', withIt: 'com o Correio Nacional' },
  'carga-pesada': { name: 'Carga Pesada Fretes', withIt: 'com a Carga Pesada Fretes' },
};

/** The parcel on the move asks for news this often; Logistics answers from DynamoDB, by key. */
const REFRESH_WHILE_MOVING_SECONDS = 5;

/** The carrier that is Tucano's own: the only one whose courier reports live positions. */
const OWN_FLEET = 'tucano-express';

export function trackingScreen(tracking: Tracking, livePath: string): Entity {
  const look = JOURNEY[tracking.status];

  return screen('tracking', {
    title: `Entrega ${tracking.trackingCode}`,
    properties: {
      trackingCode: tracking.trackingCode,
      status: tracking.status,
      statusLabel: look.label,
      tone: look.tone,
      carrier: tracking.carrier,
      carrierLabel: carrierOf(tracking.carrier).name,
      destination: tracking.destination,
      updatedAt: tracking.updatedAt,
    },
    entities: tracking.steps.map((step) => shipmentStep(step, rel.item)),
    links: [
      { rel: [rel.self], href: href(path`/tracking/${tracking.trackingCode}`) },
      { rel: [rel.up], href: href(''), title: 'Início' },
      ...liveLinks(tracking, livePath),
    ],
    ...(look.moving ? { refreshAfterSeconds: REFRESH_WHILE_MOVING_SECONDS } : {}),
  });
}

/**
 * The courier moves live only for the own fleet, and only while it is out for delivery: a
 * partner never reports a position, and the journey before or after that step has none to
 * show. The tracking page and the order both offer it under this same rule.
 */
export function liveLinks(tracking: Tracking, livePath: string): Link[] {
  if (tracking.status !== 'out_for_delivery' || tracking.carrier !== OWN_FLEET) {
    return [];
  }
  const query = new URLSearchParams({ trackingCode: tracking.trackingCode });

  return [
    {
      rel: [rel.live],
      href: `${livePath}?${query}`,
      title: 'Ver o entregador ao vivo',
    },
  ];
}

/** One step of the journey as a `timeline-step`, under the relation the screen gives it. */
export function shipmentStep(step: TrackingStep, relation: string): Entity {
  return component('timeline-step', {
    rel: [relation],
    properties: {
      status: step.status,
      label: JOURNEY[step.status].label,
      at: step.at,
      ...(step.hub === null ? {} : { hub: step.hub }),
      ...(step.attempt === null ? {} : { attempt: step.attempt }),
      ...(step.reason === null ? {} : { detail: WHY_NOT_DELIVERED[step.reason] }),
    },
  });
}

/** How a sentence says the parcel travels with a carrier: "com o Correio Nacional". */
export function travelsWith(carrier: string): string {
  return carrierOf(carrier).withIt;
}

function carrierOf(code: string): Carrier {
  const known = Object.hasOwn(CARRIERS, code) ? CARRIERS[code] : undefined;
  return known ?? { name: code, withIt: `com a transportadora ${code}` };
}

/** The form that takes a typed code to its tracking page, on any screen that offers it. */
export function trackByCodeAction(): Action {
  return {
    name: 'track-by-code',
    title: 'Acompanhar',
    method: 'GET',
    href: href('/tracking'),
    fields: [
      {
        name: 'code',
        type: 'text',
        title: 'Código de rastreio',
        required: true,
        placeholder: 'TX02PX83Y5M5G00',
        pattern: TYPED_TRACKING_CODE,
        // Room for the spaces a paste brings along: a maxlength of 15 would cut the code.
        maxlength: 20,
        autocomplete: 'off',
      },
    ],
  };
}
