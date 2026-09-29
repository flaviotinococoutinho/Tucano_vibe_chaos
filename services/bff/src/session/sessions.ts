import type { FastifyReply, FastifyRequest, HookHandlerDoneFunction } from 'fastify';
import { seal, unseal } from './seal.ts';
import { type Session, sessionOf } from './session.ts';

const COOKIE = 'tucano_session';
/** The unsigned guest id of before the sessions: expired on sight, never adopted (ADR 0030). */
const GUEST_COOKIE = 'tucano_guest';
const ONE_YEAR_SECONDS = 365 * 24 * 60 * 60;

export type SessionOptions = {
  /** The key of the HMAC that signs the cookie. */
  readonly secret: string;
  /** Secure keeps the cookie off plain HTTP; production always runs behind TLS. */
  readonly secure: boolean;
};

/** What one request knows of its session, and what the answer has to tell the browser. */
type State = {
  readonly session: Session | null;
  readonly cookie: 'as-is' | 'keep' | 'forget';
};

/**
 * The sessions of the browsers, kept in a signed cookie (RFC 6265) and nowhere else: the
 * BFF stores nothing, so a restart or a second instance knows every session all the same.
 * HttpOnly keeps it away from scripts, SameSite=Lax away from other sites' posts, and
 * Path=/bff away from everything that is not the BFF.
 */
export type Sessions = {
  /** The session of the request: the one its cookie carries, or the one this request kept since. */
  of(request: FastifyRequest): Session | null;
  /**
   * The session of the request, started here with a new profile when there is none: only
   * what needs a customer (a checkout, a profile) starts one, never a plain read.
   */
  started(request: FastifyRequest, newId: () => string): Session;
  /** Keeps the session in the browser: the cookie goes once, with the answer, whatever it is. */
  keep(request: FastifyRequest, session: Session): void;
  /** onRequest hook: a request that still carries the old guest cookie gets it expired. */
  forgetGuest(request: FastifyRequest, reply: FastifyReply, done: HookHandlerDoneFunction): void;
  /** onSend hook: writes the session cookie when the request kept one or found one unreadable. */
  writeCookie(request: FastifyRequest, reply: FastifyReply, payload: unknown): Promise<unknown>;
};

export function sessionsWith({ secret, secure }: SessionOptions): Sessions {
  const states = new WeakMap<FastifyRequest, State>();

  const cookie = (name: string, value: string, maxAgeSeconds: number): string => {
    const attributes = [
      `${name}=${value}`,
      'Path=/bff',
      `Max-Age=${maxAgeSeconds}`,
      'HttpOnly',
      'SameSite=Lax',
    ];
    return (secure ? [...attributes, 'Secure'] : attributes).join('; ');
  };

  // A cookie that does not open counts as no session at all, and the answer expires it, so
  // a browser with a forged or outdated cookie starts clean instead of warning on every request.
  const read = (request: FastifyRequest): State => {
    const value = cookieValue(request.headers.cookie, COOKIE);
    if (value === undefined) {
      return { session: null, cookie: 'as-is' };
    }
    const unsealed = unseal(value, secret);
    if ('session' in unsealed) {
      return { session: unsealed.session, cookie: 'as-is' };
    }
    request.log.warn(
      { cookie: COOKIE, problem: unsealed.problem },
      'ignored a session cookie that does not open',
    );
    return { session: null, cookie: 'forget' };
  };

  const stateOf = (request: FastifyRequest): State => {
    const known = states.get(request);
    if (known !== undefined) {
      return known;
    }
    const state = read(request);
    states.set(request, state);
    return state;
  };

  const keep = (request: FastifyRequest, session: Session): void => {
    states.set(request, { session, cookie: 'keep' });
  };

  return {
    of: (request) => stateOf(request).session,

    started(request, newId) {
      const known = stateOf(request).session;
      if (known !== null) {
        return known;
      }
      const session = sessionOf({ id: newId(), name: null });
      keep(request, session);
      return session;
    },

    keep,

    forgetGuest(request, reply, done) {
      if (cookieValue(request.headers.cookie, GUEST_COOKIE) !== undefined) {
        reply.header('set-cookie', cookie(GUEST_COOKIE, '', 0));
      }
      done();
    },

    async writeCookie(request, reply, payload) {
      const state = states.get(request);
      if (state?.cookie === 'keep' && state.session !== null) {
        reply.header('set-cookie', cookie(COOKIE, seal(state.session, secret), ONE_YEAR_SECONDS));
      } else if (state?.cookie === 'forget') {
        reply.header('set-cookie', cookie(COOKIE, '', 0));
      }
      return payload;
    },
  };
}

/** The value of a cookie in a Cookie header (RFC 6265, section 5.4), the first one that has the name. */
function cookieValue(header: string | undefined, name: string): string | undefined {
  for (const pair of (header ?? '').split(';')) {
    const at = pair.indexOf('=');
    if (at !== -1 && pair.slice(0, at).trim() === name) {
      return pair.slice(at + 1).trim();
    }
  }
  return undefined;
}
