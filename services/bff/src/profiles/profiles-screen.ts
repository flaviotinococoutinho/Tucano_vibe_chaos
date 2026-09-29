import {
  type Action,
  component,
  type Entity,
  hidden,
  href,
  inStore,
  type Link,
  rel,
  screen,
} from '../hypermedia/index.ts';
import { MAX_NAME_LENGTH, type Profile, type Session } from '../session/index.ts';
import type { Store } from '../upstream/index.ts';

/** How a profile with no name yet reads: the one a checkout started, before its first order. */
const NAMELESS = 'Visitante';

/** The name, or how a profile without one reads. */
export function labelOf(profile: Profile): string {
  return profile.name ?? NAMELESS;
}

/** The first letter of the label, for the round avatar. */
export function initialOf(profile: Profile): string {
  const [first = '?'] = labelOf(profile);
  return first.toLocaleUpperCase('pt-BR');
}

/**
 * The profiles of the browser, which belong to the platform: the same profile shops in every
 * store. Opened from a store, the screen goes back to it, and so do its actions.
 */
export function profilesScreen(session: Session | null, returnTo: Store | null): Entity {
  const profiles = session?.profiles ?? [];
  const from = returnTo?.slug;

  return screen('profiles', {
    title: 'Quem está comprando?',
    properties: {
      intro:
        'Cada perfil vale em todas as lojas e vê só os próprios pedidos. Isto é um laboratório, então não há senha: é só escolher quem está comprando.',
    },
    entities: profiles.map((profile) => profileCard(profile, profile.id === session?.active, from)),
    actions: [createProfileAction(from)],
    links: [{ rel: [rel.self], href: href('/profiles', { store: from }) }, wayBack(returnTo)],
  });
}

/** Back to the store the profiles were opened from, or to the start of the platform. */
function wayBack(returnTo: Store | null): Link {
  return returnTo === null
    ? { rel: [rel.up], href: href(''), title: 'Início' }
    : { rel: [rel.up], href: inStore(returnTo.slug), title: returnTo.name };
}

function profileCard(profile: Profile, active: boolean, from: string | undefined): Entity {
  return component('profile', {
    rel: [rel.item],
    properties: {
      profileId: profile.id,
      name: profile.name,
      label: labelOf(profile),
      initial: initialOf(profile),
      active,
    },
    // Shopping as the active profile already: only the others offer the switch.
    actions: active ? [] : [useProfileAction(profile, from)],
  });
}

/**
 * Switching changes only the session, and doing it twice lands in the same place, so the
 * form carries no idempotency key; the BFF opens it only for a profile the session holds.
 */
function useProfileAction(profile: Profile, from: string | undefined): Action {
  return {
    name: 'use-profile',
    title: `Comprar como ${labelOf(profile)}`,
    method: 'POST',
    href: href('/profiles/active', { store: from }),
    type: 'application/json',
    fields: [hidden('profileId', profile.id)],
  };
}

function createProfileAction(from: string | undefined): Action {
  return {
    name: 'create-profile',
    title: 'Criar perfil',
    method: 'POST',
    href: href('/profiles', { store: from }),
    type: 'application/json',
    fields: [
      {
        name: 'name',
        type: 'text',
        title: 'Nome',
        required: true,
        maxlength: MAX_NAME_LENGTH,
        autocomplete: 'given-name',
      },
    ],
  };
}
