import type { FastifyPluginAsync, FastifyReply, FastifyRequest } from 'fastify';
import {
  FormReader,
  href,
  InvalidForm,
  inStore,
  type KeyMaker,
  sendScreen,
} from '../hypermedia/index.ts';
import {
  MAX_NAME_LENGTH,
  MAX_PROFILES,
  profileName,
  type Sessions,
  sessionOf,
  switchedTo,
  withProfile,
} from '../session/index.ts';
import type { Stores } from '../stores/index.ts';
import {
  ServiceUnavailable,
  type Store,
  traceOf,
  UpstreamContractBroken,
} from '../upstream/index.ts';
import { profilesScreen } from './profiles-screen.ts';

export type ProfilesOptions = {
  readonly sessions: Sessions;
  readonly stores: Stores;
  readonly newId: KeyMaker;
};

/** The store the profiles were opened from, when they were: `?store=arara`. */
type FromStore = { Querystring: { store?: unknown } };

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const NAME_MESSAGE = 'Escreva o nome de quem está comprando.';

/** The ninth profile: the cookie keeps eight at most (ADR 0030). */
export class TooManyProfiles extends InvalidForm {
  constructor() {
    super(
      {
        name: [`Este navegador já guarda ${MAX_PROFILES} perfis, o máximo. Compre como um deles.`],
      },
      'Não deu para criar mais um perfil.',
    );
  }
}

/** A profile this browser does not hold: never the id of somebody else, whatever the form says. */
export class UnknownProfile extends InvalidForm {
  constructor() {
    super(
      { profileId: ['Escolha um dos perfis da lista.'] },
      'Não encontrei esse perfil neste navegador.',
    );
  }
}

/**
 * The profiles belong to the platform, not to a store (ADR 0031): the same profile shops in
 * every store. Opened from a store, the screen and its actions go back to it.
 */
export const profilesRoutes: FastifyPluginAsync<ProfilesOptions> = async (
  app,
  { sessions, stores, newId },
) => {
  // A plain read: without a session it shows no profile, and it never starts one.
  app.get<FromStore>('/v1/profiles', async (request, reply) =>
    sendScreen(reply, profilesScreen(sessions.of(request), await returnTo(stores, request))),
  );

  app.post<FromStore>('/v1/profiles', async (request, reply) => {
    const form = new FormReader(request.body);
    const name = profileName(
      form.text('name', { maxlength: MAX_NAME_LENGTH, message: NAME_MESSAGE }),
    );
    form.done();
    // Nothing printable was typed: only control characters, which the tidy name leaves out.
    if (name === '') {
      throw new InvalidForm({ name: [NAME_MESSAGE] });
    }

    const profile = { id: newId(), name };
    const current = sessions.of(request);
    const next = current === null ? sessionOf(profile) : withProfile(current, profile);
    if (next === null) {
      throw new TooManyProfiles();
    }
    sessions.keep(request, next);

    return seeWhereTo(reply, await returnTo(stores, request));
  });

  app.post<FromStore>('/v1/profiles/active', async (request, reply) => {
    const form = new FormReader(request.body);
    const profileId = form.text('profileId', {
      maxlength: 36,
      pattern: UUID_V7,
      message: 'Escolha um dos perfis da lista.',
    });
    form.done();

    const current = sessions.of(request);
    const next = current === null ? null : switchedTo(current, profileId);
    if (next === null) {
      throw new UnknownProfile();
    }
    sessions.keep(request, next);

    return seeWhereTo(reply, await returnTo(stores, request));
  });
};

/**
 * The store the profiles were opened from, when the platform has it. Where to go back to only
 * helps the way, so the profiles never wait on the catalog for it: a store the BFF cannot
 * confirm now sends the person back to the start instead.
 */
async function returnTo(stores: Stores, request: FastifyRequest<FromStore>): Promise<Store | null> {
  const { store } = request.query;
  if (typeof store !== 'string' || store === '') {
    return null;
  }
  const trace = traceOf(request);
  try {
    return await stores.find(store, trace);
  } catch (error) {
    // A timeout or an outage already left its warn line, with the call and the time it took.
    if (error instanceof ServiceUnavailable) {
      return null;
    }
    if (error instanceof UpstreamContractBroken) {
      trace.log.error({ err: error }, 'left the way back to the store out of the profiles');
      return null;
    }
    throw error;
  }
}

/**
 * 303 See Other: the browser follows with a GET, so the address bar shows where the person
 * lands, and a reload never posts the form again. From a store, it is the orders of the
 * profile shopping now in that store; from the platform, its start.
 */
function seeWhereTo(reply: FastifyReply, store: Store | null): FastifyReply {
  return reply
    .code(303)
    .header('location', store === null ? href('') : inStore(store.slug, '/orders'))
    .header('cache-control', 'no-store')
    .send();
}
