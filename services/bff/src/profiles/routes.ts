import type { FastifyPluginAsync, FastifyReply } from 'fastify';
import { FormReader, href, InvalidForm, type KeyMaker, sendScreen } from '../hypermedia/index.ts';
import {
  MAX_NAME_LENGTH,
  MAX_PROFILES,
  profileName,
  type Sessions,
  sessionOf,
  switchedTo,
  withProfile,
} from '../session/index.ts';
import { profilesScreen } from './profiles-screen.ts';

export type ProfilesOptions = {
  readonly sessions: Sessions;
  readonly newId: KeyMaker;
};

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

export const profilesRoutes: FastifyPluginAsync<ProfilesOptions> = async (
  app,
  { sessions, newId },
) => {
  // A plain read: without a session it shows no profile, and it never starts one.
  app.get('/v1/profiles', async (request, reply) =>
    sendScreen(reply, profilesScreen(sessions.of(request))),
  );

  app.post('/v1/profiles', async (request, reply) => {
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

    return seeTheOrders(reply);
  });

  app.post('/v1/profiles/active', async (request, reply) => {
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

    return seeTheOrders(reply);
  });
};

/**
 * 303 See Other: the browser follows with a GET, so the address bar shows the orders of the
 * profile shopping now, and a reload never posts the form again.
 */
function seeTheOrders(reply: FastifyReply): FastifyReply {
  return reply
    .code(303)
    .header('location', href('/orders'))
    .header('cache-control', 'no-store')
    .send();
}
