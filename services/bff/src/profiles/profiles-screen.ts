import {
  type Action,
  component,
  type Entity,
  hidden,
  href,
  rel,
  screen,
} from '../hypermedia/index.ts';
import { MAX_NAME_LENGTH, type Profile, type Session } from '../session/index.ts';

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

export function profilesScreen(session: Session | null): Entity {
  const profiles = session?.profiles ?? [];

  return screen('profiles', {
    title: 'Quem está comprando?',
    properties: {
      intro:
        'Cada perfil vê só os próprios pedidos. Isto é um laboratório, então não há senha: é só escolher quem está comprando.',
    },
    entities: profiles.map((profile) => profileCard(profile, profile.id === session?.active)),
    actions: [createProfileAction()],
    links: [
      { rel: [rel.self], href: href('/profiles') },
      { rel: [rel.up], href: href(''), title: 'Início' },
    ],
  });
}

function profileCard(profile: Profile, active: boolean): Entity {
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
    actions: active ? [] : [useProfileAction(profile)],
  });
}

/**
 * Switching changes only the session, and doing it twice lands in the same place, so the
 * form carries no idempotency key; the BFF opens it only for a profile the session holds.
 */
function useProfileAction(profile: Profile): Action {
  return {
    name: 'use-profile',
    title: `Comprar como ${labelOf(profile)}`,
    method: 'POST',
    href: href('/profiles/active'),
    type: 'application/json',
    fields: [hidden('profileId', profile.id)],
  };
}

function createProfileAction(): Action {
  return {
    name: 'create-profile',
    title: 'Criar perfil',
    method: 'POST',
    href: href('/profiles'),
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
