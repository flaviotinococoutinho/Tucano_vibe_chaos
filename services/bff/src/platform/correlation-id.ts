import type { FastifyReply, FastifyRequest, HookHandlerDoneFunction } from 'fastify';
import { v7 as uuidv7 } from 'uuid';

/** Kong sets it on every request (`uuid#counter`); calls inside the network may bring their own. */
export const CORRELATION_ID_HEADER = 'x-correlation-id';

/** The field every service uses for the id in its log lines. */
export const CORRELATION_ID_LOG_FIELD = 'correlation_id';

/** Used when the request arrives without the header, or with it empty. */
export function newCorrelationId(): string {
  return uuidv7();
}

/** Every response carries the id, errors and unknown routes included, so callers can quote it. */
export function echoCorrelationId(
  request: FastifyRequest,
  reply: FastifyReply,
  done: HookHandlerDoneFunction,
): void {
  reply.header(CORRELATION_ID_HEADER, request.id);
  done();
}
