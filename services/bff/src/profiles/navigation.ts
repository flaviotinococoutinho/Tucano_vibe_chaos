import type { FastifyReply, FastifyRequest } from 'fastify';
import {
  component,
  type Entity,
  href,
  inStore,
  type Link,
  rel,
  SIREN_JSON,
} from '../hypermedia/index.ts';
import { activeProfile, type Session, type Sessions } from '../session/index.ts';
import { brandOf, type Stores } from '../stores/index.ts';
import type { Store } from '../upstream/index.ts';
import { initialOf, labelOf } from './profiles-screen.ts';

/**
 * Where the person is and who is shopping, and where the header goes: the same component on
 * every screen, so the web draws its header, and wears the palette of the store, from the
 * screen it shows and never keeps a menu of its own. Inside a store, the links stay in it;
 * the profiles belong to the platform, and carry the store they are opened from.
 */
export function navigationOf(session: Session | null, store: Store | null): Entity {
  const shopper = session === null ? null : activeProfile(session);

  return component('navigation', {
    rel: [rel.navigation],
    properties: {
      store: store === null ? null : brandOf(store),
      shopper:
        shopper === null
          ? null
          : { profileId: shopper.id, label: labelOf(shopper), initial: initialOf(shopper) },
    },
    links: [
      { rel: [rel.stores], href: href(''), title: 'Todas as lojas' },
      ...(store === null ? [] : linksOf(store)),
      {
        rel: [rel.profiles],
        href: href('/profiles', { store: store?.slug }),
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
 * at the end of the request, in the store the request entered, so a screen builder stays a
 * pure function of what it shows. Problems, probes and anything else that is not a Siren
 * screen go out untouched.
 */
export function navigationFor(sessions: Sessions, stores: Stores) {
  return async (
    request: FastifyRequest,
    reply: FastifyReply,
    payload: unknown,
  ): Promise<unknown> =>
    isScreen(payload) && String(reply.getHeader('content-type')).startsWith(SIREN_JSON)
      ? withNavigation(payload, navigationOf(sessions.of(request), stores.of(request)))
      : payload;
}

/** The home of the store, its catalog and the orders of the shopper in it. */
function linksOf(store: Store): Link[] {
  return [
    { rel: [rel.store], href: inStore(store.slug), title: store.name },
    { rel: [rel.catalog], href: inStore(store.slug, '/products'), title: 'Catálogo' },
    { rel: [rel.orders], href: inStore(store.slug, '/orders'), title: 'Meus pedidos' },
  ];
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
