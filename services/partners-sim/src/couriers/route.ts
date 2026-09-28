import { createHash } from 'node:crypto';

/** A point on Earth, WGS 84 degrees: the shape the delivery news contract carries. */
export type LatLng = { readonly latitude: number; readonly longitude: number };

/**
 * One pickup's ride, from its fulfillment center to the tracking code's door: a quadratic
 * Bezier curve, so the reported path bends instead of walking a straight line.
 */
export type Route = {
  readonly origin: LatLng;
  readonly control: LatLng;
  readonly destination: LatLng;
};

const EARTH_RADIUS_METERS = 6_371_000;

const GRU1: LatLng = { latitude: -23.4356, longitude: -46.4731 };
const BHZ1: LatLng = { latitude: -19.9321, longitude: -44.0539 };

/** The fulfillment centers the lab knows; `centerLocation` falls back to BHZ1 for any other. */
const FULFILLMENT_CENTERS: Readonly<Record<string, LatLng>> = { GRU1, BHZ1 };

const MIN_DISTANCE_METERS = 2_500;
const MAX_DISTANCE_METERS = 7_500;
/** How far the curve bulges from the straight line: a share of the whole distance. */
const CONTROL_OFFSET_SHARE = 0.2;
/** Points sampled between the courier and the door, added up for the remaining length. */
const REMAINING_SAMPLES = 24;

/** Where a pickup's device rides from: the center's coordinates, or BHZ1 for one the lab does not know. */
export function centerLocation(center: string): LatLng {
  return FULFILLMENT_CENTERS[center] ?? BHZ1;
}

/**
 * The route of one pickup, built from facts that never change during its journey: the
 * fulfillment center and the tracking code. Hashing the tracking code is what makes the same
 * pickup ride the same path on every visit, without the device having to remember anything.
 */
export function routeFor(center: string, trackingCode: string): Route {
  const origin = centerLocation(center);
  const { bearingDegrees, distanceMeters, side } = journeyOf(trackingCode);
  const destination = destinationPoint(origin, bearingDegrees, distanceMeters);
  // The control point sits over the middle of the straight line, then moves perpendicular to
  // it: 90 degrees to one side or the other of the same bearing, picked by a hash bit of its own.
  const midpoint = destinationPoint(origin, bearingDegrees, distanceMeters / 2);
  const control = destinationPoint(
    midpoint,
    normalizeDegrees(bearingDegrees + side * 90),
    distanceMeters * CONTROL_OFFSET_SHARE,
  );

  return { origin, control, destination };
}

/** A point along the route's curve, `t` from 0 (the fulfillment center) to 1 (the door). */
export function pointAt(route: Route, t: number): LatLng {
  const clamped = Math.min(1, Math.max(0, t));
  const inverse = 1 - clamped;
  const originWeight = inverse * inverse;
  const controlWeight = 2 * inverse * clamped;
  const destinationWeight = clamped * clamped;

  return {
    latitude:
      originWeight * route.origin.latitude +
      controlWeight * route.control.latitude +
      destinationWeight * route.destination.latitude,
    longitude:
      originWeight * route.origin.longitude +
      controlWeight * route.control.longitude +
      destinationWeight * route.destination.longitude,
  };
}

/**
 * The whole number of meters left on the curve from `t` to the door: the rest of the curve
 * sampled into segments, added up by the great-circle distance between each pair. Zero at
 * `t` 1, and never negative, whatever rounding does to the last segment.
 */
export function remainingMetersAt(route: Route, t: number): number {
  const clamped = Math.min(1, Math.max(0, t));
  let meters = 0;
  let previous = pointAt(route, clamped);
  for (let sample = 1; sample <= REMAINING_SAMPLES; sample += 1) {
    const sampleT = clamped + ((1 - clamped) * sample) / REMAINING_SAMPLES;
    const point = pointAt(route, sampleT);
    meters += haversineMeters(previous, point);
    previous = point;
  }

  return Math.max(0, Math.round(meters));
}

/** The point a distance away from `origin`, along a bearing measured clockwise from north. */
export function destinationPoint(
  origin: LatLng,
  bearingDegrees: number,
  distanceMeters: number,
): LatLng {
  const angularDistance = distanceMeters / EARTH_RADIUS_METERS;
  const bearing = toRadians(bearingDegrees);
  const lat1 = toRadians(origin.latitude);
  const lon1 = toRadians(origin.longitude);

  const lat2 = Math.asin(
    Math.sin(lat1) * Math.cos(angularDistance) +
      Math.cos(lat1) * Math.sin(angularDistance) * Math.cos(bearing),
  );
  const lon2 =
    lon1 +
    Math.atan2(
      Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(lat1),
      Math.cos(angularDistance) - Math.sin(lat1) * Math.sin(lat2),
    );

  return { latitude: toDegrees(lat2), longitude: toDegrees(lon2) };
}

/** The great-circle distance between two points, in meters. */
export function haversineMeters(a: LatLng, b: LatLng): number {
  const lat1 = toRadians(a.latitude);
  const lat2 = toRadians(b.latitude);
  const deltaLat = toRadians(b.latitude - a.latitude);
  const deltaLon = toRadians(b.longitude - a.longitude);
  const h =
    Math.sin(deltaLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(deltaLon / 2) ** 2;

  return 2 * EARTH_RADIUS_METERS * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** Bearing, distance and side, all derived from one hash: the same tracking code, the same ride. */
function journeyOf(trackingCode: string): {
  readonly bearingDegrees: number;
  readonly distanceMeters: number;
  readonly side: 1 | -1;
} {
  const hash = createHash('sha256').update(trackingCode).digest();
  const bearingDegrees = unitFraction(hash, 0) * 360;
  const distanceMeters =
    MIN_DISTANCE_METERS + unitFraction(hash, 4) * (MAX_DISTANCE_METERS - MIN_DISTANCE_METERS);
  const side: 1 | -1 = (hash[8] ?? 0) % 2 === 0 ? 1 : -1;

  return { bearingDegrees, distanceMeters, side };
}

/**
 * A float in [0, 1), from 4 bytes of a hash: deterministic, and spread evenly enough for a
 * bearing or a distance to look different from one tracking code to the next.
 */
function unitFraction(hash: Buffer, offset: number): number {
  return hash.readUInt32BE(offset) / 0x1_0000_0000;
}

function normalizeDegrees(degrees: number): number {
  return ((degrees % 360) + 360) % 360;
}

function toRadians(degrees: number): number {
  return (degrees * Math.PI) / 180;
}

function toDegrees(radians: number): number {
  return (radians * 180) / Math.PI;
}
