import type { FastifyReply } from 'fastify';
import { type Entity, SIREN_JSON } from './siren.ts';

/**
 * Sends a screen. None is cached: forms carry idempotency keys made for this render, and
 * a shared cache handing the same screen to two people would make them share a key.
 */
export function sendScreen(reply: FastifyReply, entity: Entity, status = 200): FastifyReply {
  return reply.code(status).header('cache-control', 'no-store').type(SIREN_JSON).send(entity);
}
