import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { randomReceiverDocument, randomReceiverName } from '../../src/carriers/receiver.ts';
import { sequence } from '../support/random.ts';

describe('receiver', () => {
  it('names someone within the 120 characters the contract allows', () => {
    const name = randomReceiverName(() => 0.5);

    assert.ok(name.length > 0);
    assert.ok(name.length <= 120);
    assert.match(name, /^\S+ \S+$/);
  });

  it('draws a different name for a different roll', () => {
    assert.notEqual(
      randomReceiverName(() => 0.1),
      randomReceiverName(() => 0.9),
    );
  });

  it('masks a CPF within the 20 characters the contract allows, with a valid check digit', () => {
    const document = randomReceiverDocument(() => 0.5);

    assert.ok(document.length <= 20);
    assert.match(document, /^\d{3}\.\d{3}\.\d{3}-\d{2}$/);
  });

  it('computes the CPF check digits by the standard algorithm', () => {
    // floor(random() * 10) draws 1, 4 and 7 at 0.15, 0.45 and 0.75: digits 111.444.777,
    // whose check digits (35) are a well-known valid CPF used to test this very algorithm.
    const rolls = [0.15, 0.15, 0.15, 0.45, 0.45, 0.45, 0.75, 0.75, 0.75];

    assert.equal(randomReceiverDocument(sequence(...rolls)), '111.444.777-35');
  });
});
