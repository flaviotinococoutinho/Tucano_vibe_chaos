import { component, type Entity, rel } from '../hypermedia/index.ts';
import { shipmentStep } from '../tracking/index.ts';
import type {
  CancellationReason,
  Order,
  OrderStatus,
  OrderTransition,
  Tracking,
} from '../upstream/index.ts';

/** What logistics said about the parcel of an order, when the order screen asked. */
export type Delivery =
  /** No tracking code yet, or no news of the code reached logistics yet: nothing to add. */
  | { readonly news: 'none' }
  /** Logistics did not answer in time: the order goes out with its own history and a notice. */
  | { readonly news: 'unavailable' }
  /** The tracking page of the parcel, and where the web follows the courier live, when it can. */
  | { readonly news: 'known'; readonly tracking: Tracking; readonly livePath: string };

export type MilestoneState = 'done' | 'current' | 'upcoming' | 'stopped';

/** One milestone of the progress. `at` only when it is done or stopped and the BFF knows when. */
export type Milestone = {
  readonly label: string;
  readonly state: MilestoneState;
  readonly at?: string;
};

export type ProgressFacts = {
  readonly status: OrderStatus;
  readonly placedAt: string;
  readonly cancellationReason: CancellationReason | null;
  /** The status transitions the BFF knows, oldest first: all of them on the order, fewer on the list. */
  readonly transitions: readonly OrderTransition[];
  /** Right after paying, the payment milestone says it is being confirmed. */
  readonly confirming?: boolean;
  /** When logistics started preparing the parcel, once its steps are known. */
  readonly preparingSince?: string;
};

type Step = 'placed' | 'paid' | 'preparing' | 'shipped' | 'delivered';

/** The milestones every order walks through, in order. */
const STEPS: readonly (readonly [Step, string])[] = [
  ['placed', 'Pedido feito'],
  ['paid', 'Pagamento aprovado'],
  ['preparing', 'Preparando o envio'],
  ['shipped', 'A caminho'],
  ['delivered', 'Entregue'],
];

/** The milestone each moving status works toward; past the last one, the story is over. */
const CURRENT: Readonly<Record<'pending_payment' | 'paid' | 'shipped' | 'delivered', number>> = {
  pending_payment: 1,
  paid: 2,
  shipped: 3,
  delivered: STEPS.length,
};

/** The milestone a cancelled order stops at, named by why it stopped. */
const STOPPED_BECAUSE: Readonly<Record<CancellationReason, string>> = {
  payment_declined: 'Pagamento recusado',
  reservation_expired: 'Prazo para pagar acabou',
  customer_request: 'Cancelado a seu pedido',
};

/**
 * Where the order is, in five milestones. Done ones say when they happened, the current one
 * says what the order waits for, the upcoming ones say what comes next. A cancelled order
 * ends at a stopped milestone named by its reason, a returned one at "Devolvido", and
 * nothing comes after either.
 */
export function progressOf(facts: ProgressFacts): Milestone[] {
  const at = (status: OrderStatus): string | undefined =>
    facts.transitions.find((transition) => transition.status === status)?.at;
  const reachedAt: Readonly<Record<Step, string | undefined>> = {
    placed: facts.placedAt,
    paid: at('paid'),
    // Preparing starts with the payment; logistics tells the exact moment once it knows the parcel.
    preparing: facts.preparingSince ?? at('paid'),
    shipped: at('shipped'),
    delivered: at('delivered'),
  };
  const done = ([step, label]: readonly [Step, string]): Milestone =>
    milestone(label, 'done', reachedAt[step]);

  switch (facts.status) {
    case 'cancelled': {
      const reached = at('paid') === undefined ? 1 : 2;
      const why =
        facts.cancellationReason === null ? 'Cancelado' : STOPPED_BECAUSE[facts.cancellationReason];
      return [...STEPS.slice(0, reached).map(done), milestone(why, 'stopped', at('cancelled'))];
    }
    case 'returned':
      return [...STEPS.slice(0, 4).map(done), milestone('Devolvido', 'stopped', at('returned'))];
    default: {
      const current = CURRENT[facts.status];
      return STEPS.map((step, index) => {
        if (index < current) {
          return done(step);
        }
        return index === current
          ? milestone(currentLabel(step, facts), 'current')
          : milestone(step[1], 'upcoming');
      });
    }
  }
}

/** A milestone in progress reads as what is going on: the payment is awaited, or confirmed. */
function currentLabel([step, label]: readonly [Step, string], facts: ProgressFacts): string {
  if (step !== 'paid') {
    return label;
  }
  return facts.confirming === true ? 'Confirmando o pagamento' : 'Aguardando pagamento';
}

function milestone(label: string, state: MilestoneState, at?: string): Milestone {
  return at === undefined ? { label, state } : { label, state, at };
}

const ORDER_STEP_LABELS: Readonly<Record<OrderStatus, string>> = {
  pending_payment: 'Pedido feito',
  paid: 'Pagamento aprovado',
  shipped: 'Pedido enviado',
  delivered: 'Pedido entregue',
  cancelled: 'Pedido cancelado',
  returned: 'Pedido devolvido',
};

/** The reasons Commerce gives for a move, as the detail of its step. */
const WHY: Readonly<Record<string, string>> = {
  payment_declined: 'O pagamento foi recusado.',
  reservation_expired: 'O prazo para pagar acabou.',
  customer_request: 'Cancelado a seu pedido.',
};

/** What the shipment tells better, with hubs and visits: once its steps are in, the order's own leave. */
const TOLD_BY_THE_SHIPMENT: ReadonlySet<OrderStatus> = new Set([
  'shipped',
  'delivered',
  'returned',
]);

/**
 * The story of the order, oldest first: its own moves, merged with the steps of the parcel
 * once logistics tells them. The history always starts with the order being placed, even
 * when the caller knows no more than the order itself, as right after placing it.
 */
export function historyOf(
  order: Order,
  transitions: readonly OrderTransition[],
  delivery: Delivery,
): Entity[] {
  const own = transitions.some(({ status }) => status === 'pending_payment')
    ? transitions
    : [{ status: 'pending_payment' as const, at: order.placedAt, reason: null }, ...transitions];
  const shipment = delivery.news === 'known' ? delivery.tracking.steps : [];
  const kept =
    shipment.length === 0 ? own : own.filter(({ status }) => !TOLD_BY_THE_SHIPMENT.has(status));
  const steps = [
    ...kept.map((transition) => ({ at: transition.at, step: orderStep(transition) })),
    ...shipment.map((step) => ({ at: step.at, step: shipmentStep(step, rel.history) })),
  ];

  // Array sort is stable: on the same instant, the order's own move comes before the parcel's.
  return steps
    .sort((one, other) => Date.parse(one.at) - Date.parse(other.at))
    .map(({ step }) => step);
}

function orderStep(transition: OrderTransition): Entity {
  const detail =
    transition.reason !== null && Object.hasOwn(WHY, transition.reason)
      ? WHY[transition.reason]
      : undefined;

  return component('timeline-step', {
    rel: [rel.history],
    properties: {
      status: transition.status,
      label: ORDER_STEP_LABELS[transition.status],
      at: transition.at,
      ...(detail === undefined ? {} : { detail }),
    },
  });
}
