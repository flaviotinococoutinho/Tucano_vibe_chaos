import type { FastifyPluginAsync, FastifyReply } from 'fastify';
import { inStore, path, sendScreen } from '../hypermedia/index.ts';
import type { Stores } from '../stores/index.ts';
import { type Logistics, traceOf } from '../upstream/index.ts';
import { canonicalCode, InvalidTrackingCode, TRACKING_CODE, TrackingNotFound } from './code.ts';
import { trackingScreen } from './tracking-screen.ts';

export type LookupOptions = { readonly logistics: Logistics };

export type TrackingOptions = {
  readonly logistics: Logistics;
  readonly stores: Stores;
  /** Where the live tracking link points to: a path on the public origin, not a BFF route. */
  readonly livePath: string;
};

type CodeQuery = { Querystring: { code?: unknown } };

/**
 * The tracking by code of the platform: a code says nothing of its store, so logistics finds
 * the parcel across the platform, and the browser goes on to the page inside its store.
 */
export const lookupRoutes: FastifyPluginAsync<LookupOptions> = async (app, { logistics }) => {
  app.get<CodeQuery>('/v1/tracking', async (request, reply) => {
    const code = typedCode(request.query.code);
    // A parcel from before the stores has none, and no store shows it (ADR 0031).
    const store = await logistics.storeOfParcel(code, traceOf(request));
    if (store === null) {
      throw new TrackingNotFound(code);
    }

    return seeOther(reply, inStore(store, path`/tracking/${code}`));
  });
};

/** The tracking of a store, under `/v1/stores/:store`: a parcel of another store is not found here. */
export const trackingRoutes: FastifyPluginAsync<TrackingOptions> = async (
  app,
  { logistics, stores, livePath },
) => {
  // The form of track-by-code lands here. 303 See Other sends the browser to the page
  // itself, so the address bar shows the address of the parcel, not of the form.
  app.get<CodeQuery>('/tracking', async (request, reply) => {
    const store = stores.entered(request);
    const code = typedCode(request.query.code);

    return seeOther(reply, inStore(store.slug, path`/tracking/${code}`));
  });

  app.get<{ Params: { code: string } }>('/tracking/:code', async (request, reply) => {
    const store = stores.entered(request);
    const code = canonicalCode(request.params.code);
    const tracking = TRACKING_CODE.test(code)
      ? await logistics.tracking(store.slug, code, traceOf(request))
      : null;
    if (tracking === null) {
      throw new TrackingNotFound(code);
    }

    return sendScreen(reply, trackingScreen(store, tracking, livePath));
  });
};

/** The code as the form sent it, made canonical; a code out of shape is refused next to the field. */
function typedCode(typed: unknown): string {
  const code = canonicalCode(typeof typed === 'string' ? typed : '');
  if (!TRACKING_CODE.test(code)) {
    throw new InvalidTrackingCode();
  }
  return code;
}

function seeOther(reply: FastifyReply, location: string): FastifyReply {
  return reply.code(303).header('location', location).header('cache-control', 'no-store').send();
}
