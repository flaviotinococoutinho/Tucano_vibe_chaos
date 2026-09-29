import { type ReactElement, useEffect, useReducer } from 'react';
import { LiveRegion } from './LiveRegion.tsx';
import './LiveDelivery.css';

/**
 * The `LiveDelivery` card: opens the WebSocket a `rel-live` link points to and follows the
 * courier while a parcel of the own fleet is out for delivery (`contracts/tracking/README.md`).
 * The web never builds this address; it only resolves the href the BFF already handed out
 * against the page's own origin, so the scheme (`ws:`/`wss:`) always matches the page's own.
 */

const TRAIL_LIMIT = 120;
const BAND_METERS = 500;
const STALE_AFTER_SECONDS = 30;
const TICK_INTERVAL_MS = 5000;
const INITIAL_BACKOFF_MS = 1000;
const MAX_BACKOFF_MS = 15000;

const UNAVAILABLE_MESSAGE =
  'A posição ao vivo não está disponível agora. O rastreio segue se atualizando sozinho.';

// -- Wire shape (contracts/tracking/delivery-news.schema.json) ------------------------------

type Outcome = 'delivered' | 'delivery_failed';

type GeoPoint = { readonly latitude: number; readonly longitude: number };

type PositionNews = {
  readonly type: 'position';
  readonly point: GeoPoint;
  readonly remainingMeters: number;
  readonly atMs: number;
};

type EndedNews = { readonly type: 'ended'; readonly outcome: Outcome; readonly atMs: number };

type DeliveryNews = PositionNews | EndedNews;

function isFiniteNumber(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value);
}

function parseInstant(value: unknown): number | null {
  if (typeof value !== 'string') {
    return null;
  }
  const ms = Date.parse(value);
  return Number.isNaN(ms) ? null : ms;
}

/**
 * One delivery news frame, or `null` for anything that does not match the schema: a frame
 * from a future field the web does not know yet, a stray non-JSON ping, a truncated write.
 * The contract only ever grows, so unknown extra fields are ignored, never rejected.
 */
function parseDeliveryNews(data: unknown): DeliveryNews | null {
  if (typeof data !== 'string') {
    return null;
  }
  let value: unknown;
  try {
    value = JSON.parse(data);
  } catch {
    return null;
  }
  if (typeof value !== 'object' || value === null) {
    return null;
  }
  const record = value as Record<string, unknown>;
  if (typeof record.trackingCode !== 'string') {
    return null;
  }
  const atMs = parseInstant(record.at);
  if (atMs === null) {
    return null;
  }

  if (record.type === 'position') {
    const { latitude, longitude, remainingMeters } = record;
    if (
      !isFiniteNumber(latitude) ||
      latitude < -90 ||
      latitude > 90 ||
      !isFiniteNumber(longitude) ||
      longitude < -180 ||
      longitude > 180 ||
      !isFiniteNumber(remainingMeters) ||
      !Number.isInteger(remainingMeters) ||
      remainingMeters < 0
    ) {
      return null;
    }
    return { type: 'position', point: { latitude, longitude }, remainingMeters, atMs };
  }

  if (record.type === 'ended') {
    const outcome = record.outcome;
    if (outcome === 'delivered' || outcome === 'delivery_failed') {
      return { type: 'ended', outcome, atMs };
    }
  }

  return null;
}

// -- Copy ------------------------------------------------------------------------------------

function formatRemaining(meters: number): string {
  if (meters < 1000) {
    return `a ${Math.round(meters)} m`;
  }
  return `a ${(meters / 1000).toFixed(1).replace('.', ',')} km`;
}

function statusLine(remainingMeters: number): string {
  return `O entregador está ${formatRemaining(remainingMeters)}`;
}

function outcomeText(outcome: Outcome): string {
  return outcome === 'delivered' ? 'Entregue' : 'O entregador não conseguiu entregar desta vez';
}

// -- State -------------------------------------------------------------------------------------

type State = {
  readonly phase: 'connecting' | 'unavailable';
  readonly current: { readonly remainingMeters: number; readonly atMs: number } | null;
  readonly trail: readonly GeoPoint[];
  readonly outcome: Outcome | null;
  readonly staleSeconds: number | null;
  readonly announcement: string;
  /** The last 500 m band a change was announced for; `null` before the first position. */
  readonly band: number | null;
};

const initialState: State = {
  phase: 'connecting',
  current: null,
  trail: [],
  outcome: null,
  staleSeconds: null,
  announcement: '',
  band: null,
};

type Action =
  | { readonly type: 'connecting' }
  | { readonly type: 'unavailable' }
  | {
      readonly type: 'position';
      readonly point: GeoPoint;
      readonly remainingMeters: number;
      readonly atMs: number;
    }
  | { readonly type: 'ended'; readonly outcome: Outcome }
  | { readonly type: 'tick'; readonly nowMs: number };

function reducer(state: State, action: Action): State {
  // Ended is terminal: a late reconnect signal or a stray tick after it changes nothing.
  if (state.outcome !== null) {
    return state;
  }
  switch (action.type) {
    case 'connecting':
      return state.current === null && state.phase !== 'connecting'
        ? { ...state, phase: 'connecting' }
        : state;
    case 'unavailable':
      return state.current === null && state.phase !== 'unavailable'
        ? { ...state, phase: 'unavailable' }
        : state;
    case 'position': {
      const band = Math.floor(action.remainingMeters / BAND_METERS);
      const crossedBand = state.band === null || band !== state.band;
      return {
        ...state,
        current: { remainingMeters: action.remainingMeters, atMs: action.atMs },
        trail: [...state.trail, action.point].slice(-TRAIL_LIMIT),
        staleSeconds: null,
        band,
        announcement: crossedBand ? statusLine(action.remainingMeters) : state.announcement,
      };
    }
    case 'ended':
      return { ...state, outcome: action.outcome, announcement: outcomeText(action.outcome) };
    case 'tick': {
      if (state.current === null) {
        return state;
      }
      const ageSeconds = Math.floor((action.nowMs - state.current.atMs) / 1000);
      if (ageSeconds < STALE_AFTER_SECONDS) {
        return state.staleSeconds === null ? state : { ...state, staleSeconds: null };
      }
      return {
        ...state,
        staleSeconds: ageSeconds,
        announcement: state.staleSeconds === null ? 'Sem sinal do entregador' : state.announcement,
      };
    }
    default:
      return state;
  }
}

// -- Map projection ----------------------------------------------------------------------------

type PlanarPoint = { readonly x: number; readonly y: number };

const VIEWBOX_WIDTH = 300;
const VIEWBOX_HEIGHT = 200;
const VIEWBOX_PADDING = 24;
// A floor on the degrees a nearly-still (or single-point) trail spans, so the map does not
// zoom in absurdly on GPS jitter; about 200 m of latitude at the equator.
const MIN_SPAN_DEGREES = 0.002;

/**
 * Projects WGS 84 points into the viewBox: equirectangular, which is fine at the scale of one
 * delivery route, with longitude scaled by the mean latitude's cosine so the path is not
 * stretched east-west, and one shared scale on both axes so the aspect is never distorted
 * (a "contain" fit, centered, padded on every side).
 */
function project(points: readonly GeoPoint[]): readonly PlanarPoint[] {
  if (points.length === 0) {
    return [];
  }
  const latitudes = points.map((point) => point.latitude);
  const longitudes = points.map((point) => point.longitude);
  const minLat = Math.min(...latitudes);
  const maxLat = Math.max(...latitudes);
  const minLon = Math.min(...longitudes);
  const maxLon = Math.max(...longitudes);
  const centerLat = (minLat + maxLat) / 2;
  const centerLon = (minLon + maxLon) / 2;
  const lonScale = Math.cos((centerLat * Math.PI) / 180);

  const spanLat = Math.max(maxLat - minLat, MIN_SPAN_DEGREES);
  const spanLon = Math.max((maxLon - minLon) * lonScale, MIN_SPAN_DEGREES);
  const innerWidth = VIEWBOX_WIDTH - VIEWBOX_PADDING * 2;
  const innerHeight = VIEWBOX_HEIGHT - VIEWBOX_PADDING * 2;
  const scale = Math.min(innerWidth / spanLon, innerHeight / spanLat);

  return points.map((point) => ({
    x: VIEWBOX_WIDTH / 2 + (point.longitude - centerLon) * lonScale * scale,
    // North is up: SVG y grows downward, latitude grows northward.
    y: VIEWBOX_HEIGHT / 2 - (point.latitude - centerLat) * scale,
  }));
}

// -- Socket and clock, both injectable for tests ------------------------------------------------

export type Clock = {
  readonly now: () => number;
  readonly setTimeout: (callback: () => void, delayMs: number) => number;
  readonly clearTimeout: (id: number) => void;
};

const defaultClock: Clock = {
  now: () => Date.now(),
  setTimeout: (callback, delayMs) => window.setTimeout(callback, delayMs),
  clearTimeout: (id) => window.clearTimeout(id),
};

function defaultCreateSocket(url: string): WebSocket {
  return new WebSocket(url);
}

/** Resolves a same-origin path against the page's own location: `ws:` on `http:`, `wss:` else. */
function resolveLiveUrl(href: string): string {
  const resolved = new URL(href, window.location.href);
  resolved.protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  return resolved.toString();
}

export type LiveDeliveryProps = {
  /** The `rel-live` link's `href`, exactly as the BFF sent it. */
  readonly href: string;
  /** Defaults to a real `WebSocket`; a test injects a fake one. */
  readonly createSocket?: (url: string) => WebSocket;
  /** Defaults to real timers; a test injects one it can advance by hand. */
  readonly clock?: Clock;
};

/**
 * A card that follows the courier live: connects to the `rel-live` WebSocket, shows how far
 * the door still is, and draws the recent trail on a small inline map. Never blocks the rest
 * of the tracking screen: a socket that cannot connect just keeps retrying quietly.
 */
export function LiveDelivery({
  href,
  createSocket = defaultCreateSocket,
  clock = defaultClock,
}: LiveDeliveryProps): ReactElement {
  const [state, dispatch] = useReducer(reducer, initialState);

  // Owns one WebSocket at a time: connects, reconnects with a growing wait on a close or an
  // error (unless the delivery already ended, or this effect is tearing down), and resets the
  // wait once a message actually gets through.
  useEffect(() => {
    let stopped = false;
    let socket: WebSocket | null = null;
    let backoffMs = INITIAL_BACKOFF_MS;
    let retryTimer: number | null = null;

    const retry = (): void => {
      dispatch({ type: 'unavailable' });
      retryTimer = clock.setTimeout(() => {
        retryTimer = null;
        connect();
      }, backoffMs);
      backoffMs = Math.min(backoffMs * 2, MAX_BACKOFF_MS);
    };

    const connect = (): void => {
      if (stopped) {
        return;
      }
      let instance: WebSocket;
      try {
        instance = createSocket(resolveLiveUrl(href));
      } catch {
        retry();
        return;
      }
      socket = instance;
      // Either a close or an error means this attempt failed; browsers commonly fire both
      // for the same failure, so only the first of the two schedules a retry.
      let failed = false;
      const fail = (): void => {
        if (failed || stopped) {
          return;
        }
        failed = true;
        socket = null;
        retry();
      };

      instance.onopen = () => dispatch({ type: 'connecting' });
      instance.onmessage = (event) => {
        const news = parseDeliveryNews(event.data);
        if (news === null) {
          return;
        }
        backoffMs = INITIAL_BACKOFF_MS;
        if (news.type === 'position') {
          dispatch({
            type: 'position',
            point: news.point,
            remainingMeters: news.remainingMeters,
            atMs: news.atMs,
          });
        } else {
          stopped = true;
          dispatch({ type: 'ended', outcome: news.outcome });
          instance.close();
        }
      };
      instance.onerror = fail;
      instance.onclose = fail;
    };

    connect();

    return () => {
      stopped = true;
      if (retryTimer !== null) {
        clock.clearTimeout(retryTimer);
      }
      socket?.close();
    };
  }, [href, createSocket, clock]);

  // Staleness is a function of wall-clock time, not of socket state: a background reconnect
  // never needs its own UI, because a last position getting old already says enough.
  useEffect(() => {
    let timerId: number | null = null;
    const tick = (): void => {
      dispatch({ type: 'tick', nowMs: clock.now() });
      timerId = clock.setTimeout(tick, TICK_INTERVAL_MS);
    };
    timerId = clock.setTimeout(tick, TICK_INTERVAL_MS);
    return () => {
      if (timerId !== null) {
        clock.clearTimeout(timerId);
      }
    };
  }, [clock]);

  const { current, trail, outcome, phase, staleSeconds, announcement } = state;

  const statusText =
    outcome !== null
      ? outcomeText(outcome)
      : current === null
        ? phase === 'unavailable'
          ? UNAVAILABLE_MESSAGE
          : 'Conectando'
        : staleSeconds !== null
          ? `Sem sinal do entregador há ${staleSeconds} s`
          : statusLine(current.remainingMeters);

  const projected = current !== null ? project(trail) : [];
  const dot = projected[projected.length - 1];

  return (
    <section className="live-delivery card" aria-labelledby="live-delivery-heading">
      <h2 id="live-delivery-heading" className="live-delivery__title">
        Ao vivo
      </h2>
      <p className="live-delivery__status">{statusText}</p>
      {dot !== undefined ? (
        <svg
          className="live-delivery__map"
          viewBox={`0 0 ${VIEWBOX_WIDTH} ${VIEWBOX_HEIGHT}`}
          preserveAspectRatio="xMidYMid meet"
          aria-hidden="true"
        >
          <polyline
            className="live-delivery__trail"
            points={projected.map((point) => `${point.x},${point.y}`).join(' ')}
          />
          <circle className="live-delivery__dot-pulse" cx={dot.x} cy={dot.y} r={9} />
          <circle className="live-delivery__dot" cx={dot.x} cy={dot.y} r={5} />
        </svg>
      ) : null}
      <LiveRegion message={announcement} />
    </section>
  );
}
