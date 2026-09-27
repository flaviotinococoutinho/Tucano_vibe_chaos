import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { type Pickup, type PickupEvent, transition } from '../../src/carriers/pickup.ts';

const FACTS = {
  id: 'pk_01J8Z5W3Q4X9M2N7B8C6D5E4F3',
  carrier: 'correio-nacional',
  reference: 'shp-1',
  trackingCode: 'TX02PRCV4X85G00',
  origin: { center: 'GRU1', state: 'SP' },
  destination: { city: 'Belo Horizonte', state: 'MG', postalCode: '30160011' },
  createdAt: '2026-09-27T12:00:00.000Z',
} as const;

const scheduled: Pickup = { ...FACTS, status: 'scheduled', attempts: 0 };
const pickedUp: Pickup = { ...FACTS, status: 'picked_up', attempts: 0 };
const inTransit: Pickup = { ...FACTS, status: 'in_transit', attempts: 0 };
const outForDelivery1: Pickup = { ...FACTS, status: 'out_for_delivery', attempts: 1 };
const delivered: Pickup = { ...FACTS, status: 'delivered', attempts: 1 };
const failed1: Pickup = {
  ...FACTS,
  status: 'delivery_failed',
  attempts: 1,
  reason: 'recipient_absent',
};
const outForDelivery2: Pickup = { ...FACTS, status: 'out_for_delivery', attempts: 2 };
const returning: Pickup = { ...FACTS, status: 'returning', attempts: 3 };
const returned: Pickup = { ...FACTS, status: 'returned', attempts: 3 };

describe('pickup journey', () => {
  it('is picked up from scheduled', () => {
    assert.deepEqual(transition(scheduled, { type: 'picked-up' }), pickedUp);
  });

  it('reaches a hub from picked_up or from another hub, always at 0 attempts', () => {
    assert.deepEqual(transition(pickedUp, { type: 'hub-scanned' }), inTransit);
    assert.deepEqual(transition(inTransit, { type: 'hub-scanned' }), inTransit);
  });

  it('goes out for delivery as attempt 1 from picked_up or in_transit', () => {
    assert.deepEqual(transition(pickedUp, { type: 'out-for-delivery' }), outForDelivery1);
    assert.deepEqual(transition(inTransit, { type: 'out-for-delivery' }), outForDelivery1);
  });

  it('is delivered from out_for_delivery, keeping the attempt', () => {
    assert.deepEqual(transition(outForDelivery1, { type: 'delivered' }), delivered);
  });

  it('fails a delivery from out_for_delivery, keeping the attempt and naming the reason', () => {
    const event: PickupEvent = { type: 'delivery-failed', reason: 'recipient_absent' };

    assert.deepEqual(transition(outForDelivery1, event), failed1);
  });

  it('goes out for delivery again after a failure, one attempt further', () => {
    assert.deepEqual(transition(failed1, { type: 'out-for-delivery' }), outForDelivery2);
  });

  it('starts returning from a failure, dropping the reason, and is returned from returning', () => {
    const atThirdFailure: Pickup = { ...failed1, attempts: 3 };

    assert.deepEqual(transition(atThirdFailure, { type: 'returning' }), returning);
    assert.deepEqual(transition(returning, { type: 'returned' }), returned);
  });

  it('drops a stale reason once a failed visit is retried', () => {
    const retried = transition(failed1, { type: 'out-for-delivery' });

    assert.deepEqual(retried, outForDelivery2);
    assert.ok(!('reason' in retried));
  });

  it('refuses every other change and says why', () => {
    const refusals: [Pickup, PickupEvent, string][] = [
      [scheduled, { type: 'out-for-delivery' }, 'is scheduled and cannot go out for delivery'],
      [pickedUp, { type: 'picked-up' }, 'is picked_up and cannot be picked up'],
      [
        outForDelivery1,
        { type: 'hub-scanned' },
        'is out_for_delivery and cannot be scanned at a hub',
      ],
      [failed1, { type: 'delivered' }, 'is delivery_failed and cannot be delivered'],
      [returning, { type: 'out-for-delivery' }, 'is returning and cannot go out for delivery'],
      [delivered, { type: 'returning' }, 'is delivered and cannot start its way back'],
      [returned, { type: 'returned' }, 'is returned and cannot be back at the fulfillment center'],
    ];

    for (const [pickup, event, why] of refusals) {
      assert.throws(() => transition(pickup, event), {
        name: 'TransitionNotAllowed',
        category: 'conflict',
        message: `Pickup ${pickup.id} ${why}.`,
      });
    }
  });
});
