import type { FastifyReply, FastifyRequest } from 'fastify';
import type { KeyMaker } from '../hypermedia/index.ts';

const COOKIE = 'tucano_guest';
const ONE_YEAR_SECONDS = 365 * 24 * 60 * 60;
const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

export type GuestCookie = {
  readonly newId: KeyMaker;
  /** Secure keeps the cookie off plain HTTP; production always runs behind TLS. */
  readonly secure: boolean;
};

/**
 * The guest this browser shops as. The lab has no login, and Commerce wants a customer
 * id, so the BFF gives each browser a UUIDv7 in a cookie (RFC 6265) the first time it
 * opens a checkout. The same id travels in every retry of an order, which keeps the
 * body the same for the same idempotency key.
 *
 * HttpOnly keeps it away from scripts, SameSite=Lax away from other sites' posts, and
 * Path=/bff away from everything that is not the BFF. In a real store the id would come
 * from the session of a signed-in customer, never from the browser.
 */
export function guestOf(request: FastifyRequest, reply: FastifyReply, cookie: GuestCookie): string {
  const known = cookieValue(request.headers.cookie ?? '');
  if (known !== undefined && UUID_V7.test(known)) {
    return known;
  }
  const id = cookie.newId();
  const attributes = [
    `${COOKIE}=${id}`,
    'Path=/bff',
    `Max-Age=${ONE_YEAR_SECONDS}`,
    'HttpOnly',
    'SameSite=Lax',
  ];
  reply.header('set-cookie', (cookie.secure ? [...attributes, 'Secure'] : attributes).join('; '));

  return id;
}

function cookieValue(header: string): string | undefined {
  for (const pair of header.split(';')) {
    const [name, value] = pair.trim().split('=', 2);
    if (name === COOKIE) {
      return value;
    }
  }
  return undefined;
}
