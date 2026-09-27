import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  type Charge,
  type ChargeEvent,
  type Refund,
  transition,
} from '../../src/payfake/charge.ts';

const processing: Charge = {
  id: 'ch_01J8Z5W3Q4X9M2N7B8C6D5E4F3',
  status: 'processing',
  amount: { value: 18990, currency: 'BRL' },
  reference: 'pay-1',
  createdAt: '2026-09-27T12:00:00.000Z',
};
const succeeded: Charge = { ...processing, status: 'succeeded' };
const failed: Charge = { ...processing, status: 'failed', failureCode: 'card_declined' };
const refund: Refund = {
  id: 're_01J8Z5W3Q4X9M2N7B8C6D5E4F3',
  chargeId: processing.id,
  status: 'processing',
  amount: processing.amount,
  createdAt: '2026-09-27T12:05:00.000Z',
};
const refunded: Charge = { ...processing, status: 'refunded', refund };
const refundDone: Charge = { ...refunded, refund: { ...refund, status: 'succeeded' } };

describe('charge lifecycle', () => {
  it('settles a processing charge as succeeded', () => {
    assert.deepEqual(transition(processing, { type: 'succeeded' }), succeeded);
  });

  it('settles a processing charge as failed, with the reason', () => {
    const event: ChargeEvent = { type: 'failed', failureCode: 'insufficient_funds' };

    assert.deepEqual(transition(processing, event), {
      ...processing,
      status: 'failed',
      failureCode: 'insufficient_funds',
    });
  });

  it('refunds a succeeded charge while the refund is still processing', () => {
    assert.deepEqual(transition(succeeded, { type: 'refund-requested', refund }), refunded);
  });

  it('completes the refund of a refunded charge', () => {
    assert.deepEqual(transition(refunded, { type: 'refund-succeeded' }), refundDone);
  });

  it('refuses every other change and says why', () => {
    const refusals: [Charge, ChargeEvent, string][] = [
      [processing, { type: 'refund-requested', refund }, 'is processing and cannot be refunded'],
      [processing, { type: 'refund-succeeded' }, 'is processing and cannot complete a refund'],
      [succeeded, { type: 'succeeded' }, 'is succeeded and cannot succeed'],
      [succeeded, { type: 'failed', failureCode: 'card_declined' }, 'is succeeded and cannot fail'],
      [failed, { type: 'succeeded' }, 'is failed and cannot succeed'],
      [failed, { type: 'refund-requested', refund }, 'is failed and cannot be refunded'],
      [refunded, { type: 'refund-requested', refund }, 'is refunded and cannot be refunded'],
      [refundDone, { type: 'refund-succeeded' }, 'is refunded and cannot complete a refund'],
    ];

    for (const [charge, event, why] of refusals) {
      assert.throws(() => transition(charge, event), {
        name: 'TransitionNotAllowed',
        category: 'conflict',
        message: `Charge ${charge.id} ${why}.`,
      });
    }
  });
});
