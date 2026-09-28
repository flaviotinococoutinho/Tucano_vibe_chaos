import type { FastifyPluginAsync } from 'fastify';
import { href, path, sendScreen } from '../hypermedia/index.ts';
import { type Logistics, traceOf } from '../upstream/index.ts';
import { canonicalCode, InvalidTrackingCode, TRACKING_CODE, TrackingNotFound } from './code.ts';
import { trackingScreen } from './tracking-screen.ts';

export type TrackingOptions = { readonly logistics: Logistics };

export const trackingRoutes: FastifyPluginAsync<TrackingOptions> = async (app, { logistics }) => {
  // The form of track-by-code lands here. 303 See Other sends the browser to the page
  // itself, so the address bar shows the address of the parcel, not of the form.
  app.get<{ Querystring: { code?: string } }>('/v1/tracking', async (request, reply) => {
    const code = canonicalCode(request.query.code ?? '');
    if (!TRACKING_CODE.test(code)) {
      throw new InvalidTrackingCode();
    }

    return reply
      .code(303)
      .header('location', href(path`/tracking/${code}`))
      .header('cache-control', 'no-store')
      .send();
  });

  app.get<{ Params: { code: string } }>('/v1/tracking/:code', async (request, reply) => {
    const code = canonicalCode(request.params.code);
    const tracking = TRACKING_CODE.test(code)
      ? await logistics.tracking(code, traceOf(request))
      : null;
    if (tracking === null) {
      throw new TrackingNotFound(code);
    }

    return sendScreen(reply, trackingScreen(tracking));
  });
};
