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

const WHY_NOT_DELIVERED: Readonly<Record<DeliveryFailure, string>> = {
  recipient_absent: 'ninguém em casa',
  address_not_found: 'endereço não encontrado',
  recipient_refused: 'o destinatário recusou',
};

/**
 * The names people read for the carriers. Logistics keeps them too (CarrierSeeder);
 * a carrier the BFF does not know yet shows its code until it gets a name here.
 */
const CARRIERS: Readonly<Record<string, string>> = {
  'tucano-express': 'Tucano Express',
  ligeirinho: 'Ligeirinho Transportes',
  'correio-nacional': 'Correio Nacional',
  'carga-pesada': 'Carga Pesada Fretes',
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
      carrierLabel: CARRIERS[tracking.carrier] ?? tracking.carrier,
      destination: tracking.destination,
      updatedAt: tracking.updatedAt,
    },
    entities: tracking.steps.map(timelineStep),
    links: [
      { rel: [rel.self], href: href(path`/tracking/${tracking.trackingCode}`) },
      { rel: [rel.up], href: href(''), title: 'Início' },
      ...liveLink(tracking, livePath),
    ],
    ...(look.moving ? { refreshAfterSeconds: REFRESH_WHILE_MOVING_SECONDS } : {}),
  });
}

/**
 * The courier moves live only for the own fleet, and only while it is out for delivery: a
 * partner never reports a position, and the journey before or after that step has none to show.
 */
function liveLink(tracking: Tracking, livePath: string): Link[] {
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

function timelineStep(step: TrackingStep): Entity {
  const label = JOURNEY[step.status].label;

  return component('timeline-step', {
    rel: [rel.item],
    properties: {
      status: step.status,
      label: step.reason === null ? label : `${label}: ${WHY_NOT_DELIVERED[step.reason]}`,
      at: step.at,
      ...(step.hub === null ? {} : { hub: step.hub }),
      ...(step.attempt === null ? {} : { attempt: step.attempt }),
    },
  });
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
