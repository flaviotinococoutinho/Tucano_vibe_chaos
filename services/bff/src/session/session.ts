/**
 * A shopper of this browser. The lab has no login: each profile is a customer of its own,
 * and switching profiles plays the part of signing in with another account (ADR 0030).
 */
export type Profile = {
  /** The customer id Commerce keeps the orders under: a UUIDv7 the BFF made, never the browser. */
  readonly id: string;
  /** A first name at most, or null until the profile places its first order. */
  readonly name: string | null;
};

/** The profiles of one browser, and the one shopping now. */
export type Session = {
  readonly active: string;
  readonly profiles: readonly Profile[];
};

/** A cookie holds a few of them; eight keep it far below the 4096 bytes browsers promise. */
export const MAX_PROFILES = 8;

export const MAX_NAME_LENGTH = 40;

/** A session that starts with this profile, shopping. */
export function sessionOf(profile: Profile): Session {
  return { active: profile.id, profiles: [profile] };
}

/** The profile shopping now. A session that holds is never without it. */
export function activeProfile(session: Session): Profile {
  const profile = session.profiles.find(({ id }) => id === session.active);
  if (profile === undefined) {
    throw new Error('A session always holds its active profile.');
  }
  return profile;
}

/** The session with one more profile, shopping from now on; null when the browser holds the most it can. */
export function withProfile(session: Session, profile: Profile): Session | null {
  if (session.profiles.length >= MAX_PROFILES) {
    return null;
  }
  return { active: profile.id, profiles: [...session.profiles, profile] };
}

/** The session shopping as another of its profiles; null when it has no profile with that id. */
export function switchedTo(session: Session, profileId: string): Session | null {
  return session.profiles.some(({ id }) => id === profileId)
    ? { active: profileId, profiles: session.profiles }
    : null;
}

/**
 * The session whose active profile got a name, when it had none: the profile a checkout
 * started is named by its first order. A named profile keeps its name, and the same session
 * comes back when nothing changes.
 */
export function withActiveNamed(session: Session, name: string | null): Session {
  const active = activeProfile(session);
  if (active.name !== null || name === null) {
    return session;
  }
  return {
    active: session.active,
    profiles: session.profiles.map((profile) =>
      profile.id === active.id ? { id: profile.id, name } : profile,
    ),
  };
}

/**
 * The first word of a full name, and no more: the cookie keeps only what the header needs
 * to say who is shopping (data minimization), never the whole name typed in the checkout.
 */
export function firstNameOf(fullName: string): string | null {
  const first = profileName(fullName).split(' ')[0] ?? '';
  return first === '' ? null : first;
}

/**
 * A name as people type it, made tidy: composed accents, no control characters, one space
 * between words, trimmed, and 40 characters at most. Empty when nothing printable was typed.
 */
export function profileName(typed: string): string {
  const tidy = typed
    .normalize('NFC')
    .replace(/\p{Cc}/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  return upTo(MAX_NAME_LENGTH, tidy).trim();
}

/** The text cut to a length, as HTML counts it (UTF-16), without splitting a character in two. */
function upTo(maxLength: number, text: string): string {
  let cut = '';
  for (const character of text) {
    if (cut.length + character.length > maxLength) {
      break;
    }
    cut += character;
  }
  return cut;
}
