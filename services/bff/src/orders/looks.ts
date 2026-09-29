import type { Tone } from '../hypermedia/index.ts';
import type { OrderStatus } from '../upstream/index.ts';

export type Look = {
  readonly label: string;
  readonly tone: Tone;
  /** While something is about to happen, the screen asks to be fetched again. */
  readonly refreshAfterSeconds?: number;
};

/** The parcel on the move asks for news this often, and so does an order missing its delivery news. */
export const REFRESH_WHILE_MOVING_SECONDS = 5;

/**
 * How each status reads, in the order and in the list. The order refreshes itself while
 * the parcel is being prepared or is on its way, and stops when the story ends:
 * delivered, cancelled, returned.
 */
const LOOKS: Readonly<Record<OrderStatus, Look>> = {
  pending_payment: { label: 'Aguardando pagamento', tone: 'waiting' },
  paid: {
    label: 'Preparando o envio',
    tone: 'info',
    refreshAfterSeconds: REFRESH_WHILE_MOVING_SECONDS,
  },
  shipped: { label: 'A caminho', tone: 'info', refreshAfterSeconds: REFRESH_WHILE_MOVING_SECONDS },
  delivered: { label: 'Entregue', tone: 'success' },
  cancelled: { label: 'Cancelado', tone: 'danger' },
  returned: { label: 'Devolvido', tone: 'neutral' },
};

/** Right after paying: the PSP answers by webhook in a second or two, so the screen asks often. */
export const CONFIRMING: Look = {
  label: 'Confirmando o pagamento',
  tone: 'waiting',
  refreshAfterSeconds: 2,
};

export function lookOf(status: OrderStatus): Look {
  return LOOKS[status];
}
