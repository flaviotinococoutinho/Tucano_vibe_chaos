import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { sign, verify } from '../../src/payfake/signature.ts';

const SECRET = 'whsec_local_payfake';
const BODY = '{"id":"evt_01J8Z5W3Q4X9M2N7B8C6D5E4F3","type":"charge.succeeded"}';
const SIGNED_AT = 1_790_510_400;

describe('webhook signature', () => {
  it('signs "<t>.<raw body>" with HMAC-SHA256 in hex, the vector commerce checks too', () => {
    // Computed with `openssl dgst -sha256 -hmac`, not with the code under test.
    const hmac = '0605457e458dd9fea675921d4df5754d4330d115baa8c60beb64f86dc29f4061';

    assert.equal(sign(SECRET, BODY, SIGNED_AT), `t=${SIGNED_AT},v1=${hmac}`);
  });

  it('accepts what it signed', () => {
    const header = sign(SECRET, BODY, SIGNED_AT);

    assert.equal(verify({ secret: SECRET, payload: BODY, header, now: SIGNED_AT }), 'valid');
  });

  it('refuses a body changed after signing, even by one character', () => {
    const header = sign(SECRET, BODY, SIGNED_AT);
    const tampered = BODY.replace('succeeded', 'Succeeded');

    assert.equal(verify({ secret: SECRET, payload: tampered, header, now: SIGNED_AT }), 'mismatch');
  });

  it('refuses a signature made with another secret', () => {
    const header = sign('whsec_someone_else', BODY, SIGNED_AT);

    assert.equal(verify({ secret: SECRET, payload: BODY, header, now: SIGNED_AT }), 'mismatch');
  });

  it('refuses a timestamp swapped for a fresh one, because the time is inside the HMAC', () => {
    const header = sign(SECRET, BODY, SIGNED_AT).replace(`t=${SIGNED_AT}`, `t=${SIGNED_AT + 600}`);

    assert.equal(
      verify({ secret: SECRET, payload: BODY, header, now: SIGNED_AT + 600 }),
      'mismatch',
    );
  });

  it('refuses a timestamp more than five minutes away from the receiver clock', () => {
    const header = sign(SECRET, BODY, SIGNED_AT);
    const verdictAt = (now: number) => verify({ secret: SECRET, payload: BODY, header, now });

    assert.equal(verdictAt(SIGNED_AT + 300), 'valid');
    assert.equal(verdictAt(SIGNED_AT + 301), 'stale');
    assert.equal(verdictAt(SIGNED_AT - 301), 'stale');
  });

  it('takes a tolerance of its own', () => {
    const header = sign(SECRET, BODY, SIGNED_AT);
    const verdict = verify({
      secret: SECRET,
      payload: BODY,
      header,
      now: SIGNED_AT + 61,
      toleranceSeconds: 60,
    });

    assert.equal(verdict, 'stale');
  });

  it('accepts any of several v1 values, so a secret can rotate without downtime', () => {
    const oldSignature = sign('whsec_old', BODY, SIGNED_AT);
    const newSignature = sign(SECRET, BODY, SIGNED_AT).replace(`t=${SIGNED_AT},`, '');
    const header = `${oldSignature}, ${newSignature}`;

    assert.equal(verify({ secret: SECRET, payload: BODY, header, now: SIGNED_AT }), 'valid');
  });

  it('refuses a header it cannot read', () => {
    const v1 = sign(SECRET, BODY, SIGNED_AT).split(',')[1];
    const headers = ['', 'garbage', `t=${SIGNED_AT}`, String(v1), `t=soon,${v1}`, `t=1,v1=abc`];

    for (const header of headers) {
      assert.equal(
        verify({ secret: SECRET, payload: BODY, header, now: SIGNED_AT }),
        'malformed',
        header,
      );
    }
  });
});
