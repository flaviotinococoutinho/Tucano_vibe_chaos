import type { FastifyReply, FastifyRequest } from 'fastify';
import { component, type Entity, href, rel, SIREN_JSON } from '../hypermedia/index.ts';
import { activeProfile, type Session, type Sessions } from '../session/index.ts';
import { initialOf, labelOf } from './profiles-screen.ts';

/**
 * Who is shopping and where the header goes: the same component on every screen, so the web
 * draws its header from the screen it shows and never keeps a menu of its own.
 */
export function navigationOf(session: Session | null): Entity {
  const shopper = session === null ? null : activeProfile(session);

  return component('navigation', {
    rel: [rel.navigation],
    properties: {
      shopper:
        shopper === null
          ? null
          : { profileId: shopper.id, label: labelOf(shopper), initial: initialOf(shopper) },
    },
    links: [
      { rel: [rel.catalog], href: href('/products'), title: 'Catálogo' },
      { rel: [rel.orders], href: href('/orders'), title: 'Meus pedidos' },
      {
        rel: [rel.profiles],
        href: href('/profiles'),
        title: shopper === null ? 'Entrar' : labelOf(shopper),
      },
    ],
  });
}

/** The screen with the navigation embedded after its own entities. */
export function withNavigation(screen: Entity, navigation: Entity): Entity {
  const { entities = [], actions, links, ...head } = screen;

  return {
    ...head,
    entities: [...entities, navigation],
    ...(actions === undefined ? {} : { actions }),
    ...(links === undefined ? {} : { links }),
  };
}

/**
 * preSerialization hook: every screen leaves with the navigation of the session as it stands
 * at the end of the request, so a screen builder stays a pure function of what it shows.
 * Problems, probes and anything else that is not a Siren screen go out untouched.
 */
export function navigationFor(sessions: Sessions) {
  return async (
    request: FastifyRequest,
    reply: FastifyReply,
    payload: unknown,
  ): Promise<unknown> =>
    isScreen(payload) && String(reply.getHeader('content-type')).startsWith(SIREN_JSON)
      ? withNavigation(payload, navigationOf(sessions.of(request)))
      : payload;
}

function isScreen(payload: unknown): payload is Entity {
  return (
    typeof payload === 'object' &&
    payload !== null &&
    'class' in payload &&
    Array.isArray(payload.class) &&
    payload.class[0] === 'screen'
  );
}
