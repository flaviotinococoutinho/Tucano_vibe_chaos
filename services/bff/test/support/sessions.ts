import { type Session, seal, unseal } from '../../src/session/index.ts';
import { TEST_SESSION_SECRET } from './config.ts';

type SetCookie = string | string[] | undefined;

/** The Cookie header of a browser that holds this session. */
export function sessionCookie(session: Session, secret = TEST_SESSION_SECRET): string {
  return `tucano_session=${seal(session, secret)}`;
}

/** The Set-Cookie lines of a response, however many it has. */
export function setCookies(header: SetCookie): string[] {
  return header === undefined ? [] : [header].flat();
}

/** The session cookie a response set, as the browser would send it back; undefined when none. */
export function sessionCookieSet(header: SetCookie): string | undefined {
  const line = setCookies(header).find((cookie) => cookie.startsWith('tucano_session='));
  return line?.split(';')[0];
}

/** The session a response gave the browser, opened with the key of the tests. */
export function sessionSet(header: SetCookie): Session {
  const cookie = sessionCookieSet(header);
  if (cookie === undefined) {
    throw new Error('the response set no session cookie');
  }
  const unsealed = unseal(cookie.slice('tucano_session='.length), TEST_SESSION_SECRET);
  if (!('session' in unsealed)) {
    throw new Error(`the session cookie did not open: ${unsealed.problem}`);
  }
  return unsealed.session;
}
