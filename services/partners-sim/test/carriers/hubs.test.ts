import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { hubFor } from '../../src/carriers/hubs.ts';

describe('sorting hubs', () => {
  it('names the hub of a state the lab knows', () => {
    assert.equal(hubFor('SP'), 'Hub Cajamar (SP)');
    assert.equal(hubFor('MG'), 'Hub Contagem (MG)');
  });

  it('falls back to a bare name for a state without a hub city', () => {
    assert.equal(hubFor('AC'), 'Hub AC');
    assert.equal(hubFor('RR'), 'Hub RR');
  });
});
