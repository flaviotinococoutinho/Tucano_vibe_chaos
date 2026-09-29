import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { after, before, describe, it } from 'node:test';
import { buildApp } from '../src/app.ts';
import { seal, unseal } from '../src/session/index.ts';
import { bffOver } from './support/bff.ts';
import { TEST_SESSION_SECRET, testConfig } from './support/config.ts';
import { type FakeServices, fakeServices, ids, serveStores } from './support/fake-services.ts';
import { sessionCookie, sessionCookieSet, sessionSet, setCookies } from './support/sessions.ts';
import { ana, anaShopping, bruno, visitor } from './support/upstream-data.ts';

const SECRET = TEST_SESSION_SECRET;
/** The unsigned guest id of before the sessions, as an old browser still sends it. */
const GUEST = '0199a2b4-1111-7222-8333-444455556666';

/** A value sealed with the right key over any content, as only the holder of the key could. */
function signed(content: string, secret = SECRET): string {
  const payload = Buffer.from(content, 'utf8').toString('base64url');
  return `${payload}.${createHmac('sha256', secret).update(payload).digest('base64url')}`;
}

describe('the sealed session', () => {
  it('opens what it sealed', () => {
    assert.deepStrictEqual(unseal(seal(anaShopping, SECRET), SECRET), { session: anaShopping });
  });

  it('does not open a value somebody changed', () => {
    const [payload = '', signature = ''] = seal(anaShopping, SECRET).split('.');
    const asBruno = Buffer.from(
      JSON.stringify({ ...anaShopping, active: bruno.id }),
      'utf8',
    ).toString('base64url');
    const lastOf = signature.at(-1) === 'A' ? 'B' : 'A';

    assert.deepStrictEqual(unseal(`${asBruno}.${signature}`, SECRET), { problem: 'signature' });
    assert.deepStrictEqual(unseal(`${payload}.${signature.slice(0, -1)}${lastOf}`, SECRET), {
      problem: 'signature',
    });
    assert.deepStrictEqual(unseal(`${payload}.${signature.slice(0, -1)}`, SECRET), {
      problem: 'signature',
    });
  });

  it('does not open a value sealed with another key', () => {
    const elsewhere = seal(anaShopping, 'another-secret-just-as-long-as-ours');

    assert.deepStrictEqual(unseal(elsewhere, SECRET), { problem: 'signature' });
  });

  it('does not read a value longer than a cookie can be', () => {
    assert.deepStrictEqual(unseal(`${'a'.repeat(4096)}.b`, SECRET), { problem: 'oversized' });
  });

  it('does not read a value out of shape', () => {
    for (const value of ['', 'nodot', 'a.b.c', 'a+b.c', 'a.b=']) {
      assert.deepStrictEqual(unseal(value, SECRET), { problem: 'malformed' }, value);
    }
  });

  it('does not take for a session what only looks like one, even well signed', () => {
    const profiles = (count: number) =>
      Array.from({ length: count }, (_, index) => ({
        id: `0199a2b4-5a1e-7c3d-8e4f-a1b2c3d4e5${String(index).padStart(2, '0')}`,
        name: null,
      }));
    const contents = [
      'not json',
      '[]',
      JSON.stringify({ active: ana.id, profiles: [] }),
      JSON.stringify({ active: ana.id, profiles: profiles(9) }),
      JSON.stringify({ active: bruno.id, profiles: [ana] }),
      JSON.stringify({ active: ana.id, profiles: [ana, ana] }),
      JSON.stringify({ active: 'guest', profiles: [{ id: 'guest', name: null }] }),
      JSON.stringify({ active: ana.id, profiles: [{ id: ana.id, name: '' }] }),
      JSON.stringify({ active: ana.id, profiles: [{ id: ana.id, name: ' Ana' }] }),
      JSON.stringify({ active: ana.id, profiles: [{ id: ana.id, name: 'A'.repeat(41) }] }),
      JSON.stringify({ active: ana.id, profiles: [{ id: ana.id, name: 'Ana\u0000' }] }),
    ];

    for (const content of contents) {
      assert.deepStrictEqual(unseal(signed(content), SECRET), { problem: 'content' }, content);
    }
  });

  it('holds eight profiles with the longest names well within a cookie', () => {
    const longest = Array.from({ length: 8 }, (_, index) => ({
      id: `0199a2b4-5a1e-7c3d-8e4f-a1b2c3d4e5${String(index).padStart(2, '0')}`,
      name: 'Ç'.repeat(40),
    }));
    const session = { active: longest[0]?.id ?? '', profiles: longest };
    const sealed = seal(session, SECRET);

    assert.ok(sealed.length < 4096, `${sealed.length} characters`);
    assert.deepStrictEqual(unseal(sealed, SECRET), { session });
  });
});

describe('the session cookie', () => {
  let services: FakeServices;

  before(async () => {
    services = await fakeServices((app) => serveStores(app));
  });

  after(() => services.close());

  const app = (env: Record<string, string> = {}, newId = ids(visitor.id)) =>
    bffOver(services, { env, newId });

  it('is never started by a plain read', async () => {
    for (const url of [
      '/v1',
      '/v1/stores/arara',
      '/v1/stores/arara/orders',
      '/v1/profiles?store=arara',
    ]) {
      const response = await app().inject({ method: 'GET', url });

      assert.equal(response.statusCode, 200, url);
      assert.equal(response.headers['set-cookie'], undefined, url);
    }
  });

  it('expires the old guest cookie on sight, and never adopts its id', async () => {
    const read = await app().inject({
      method: 'GET',
      url: '/v1/profiles',
      headers: { cookie: `tucano_guest=${GUEST}` },
    });
    const created = await app().inject({
      method: 'POST',
      url: '/v1/profiles',
      headers: { cookie: `tucano_guest=${GUEST}` },
      payload: { name: 'Ana' },
    });

    assert.deepStrictEqual(setCookies(read.headers['set-cookie']), [
      'tucano_guest=; Path=/bff; Max-Age=0; HttpOnly; SameSite=Lax; Secure',
    ]);
    assert.deepStrictEqual(sessionSet(created.headers['set-cookie']), {
      active: visitor.id,
      profiles: [{ id: visitor.id, name: 'Ana' }],
    });
  });

  it('counts a cookie that does not open as no session, warns without its value, and expires it', async () => {
    const lines: string[] = [];
    const forged = seal(anaShopping, 'a-secret-somebody-guessed-wrong-again');
    const response = await buildApp({
      config: testConfig({ LOG_LEVEL: 'info' }),
      logStream: { write: (line) => lines.push(line) },
    }).inject({
      method: 'GET',
      url: '/v1/profiles',
      headers: { cookie: `tucano_session=${forged}` },
    });

    assert.equal(response.statusCode, 200);
    assert.equal(response.json().entities.length, 1);
    assert.deepStrictEqual(setCookies(response.headers['set-cookie']), [
      'tucano_session=; Path=/bff; Max-Age=0; HttpOnly; SameSite=Lax; Secure',
    ]);
    const warning = lines.map((line) => JSON.parse(line)).find((line) => line.level === 'warn');
    assert.partialDeepStrictEqual(warning, {
      cookie: 'tucano_session',
      problem: 'signature',
      message: 'ignored a session cookie that does not open',
    });
    assert.equal(
      lines.some((line) => line.includes(forged.split('.')[0] ?? forged)),
      false,
    );
  });

  it('is Secure only in production, where the store runs behind TLS', async () => {
    const response = await app({ APP_ENV: 'local' }).inject({
      method: 'POST',
      url: '/v1/profiles',
      payload: { name: 'Ana' },
    });

    assert.match(
      String(sessionCookieSet(response.headers['set-cookie'])),
      /^tucano_session=[\w-]+\.[\w-]+$/,
    );
    assert.doesNotMatch(String(response.headers['set-cookie']), /Secure/);
  });

  it('goes out once, with the last session the request kept', async () => {
    const carla = '0199a2b4-5d41-7f60-b172-d4e5f6071829';
    const response = await app({}, ids(carla)).inject({
      method: 'POST',
      url: '/v1/stores/arara/orders',
      payload: {},
      headers: { cookie: `tucano_guest=${GUEST}` },
    });
    const created = await app({}, ids(carla)).inject({
      method: 'POST',
      url: '/v1/profiles',
      headers: { cookie: sessionCookie(anaShopping) },
      payload: { name: 'Carla' },
    });

    // A form that needs fixing starts no session: the guest cookie is the only one that leaves.
    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(setCookies(response.headers['set-cookie']), [
      'tucano_guest=; Path=/bff; Max-Age=0; HttpOnly; SameSite=Lax; Secure',
    ]);
    assert.equal(
      setCookies(created.headers['set-cookie']).filter((cookie) =>
        cookie.startsWith('tucano_session='),
      ).length,
      1,
    );
    assert.deepStrictEqual(sessionSet(created.headers['set-cookie']), {
      active: carla,
      profiles: [...anaShopping.profiles, { id: carla, name: 'Carla' }],
    });
  });
});
