import type { FastifyBaseLogger, FastifyRequest } from 'fastify';
import { DomainError } from '../platform/domain-error.ts';
import { Fields, UpstreamContractBroken } from './fields.ts';

/** A service behind the BFF, as the configuration points to it. */
export type Upstream = {
  /** The name in the logs: catalog, commerce, logistics. */
  readonly name: string;
  /** How a person calls it in a sentence: "o catálogo". */
  readonly called: string;
  readonly baseUrl: string;
  readonly timeoutMs: number;
};

/** What every call to a service takes from the request that caused it. */
export type Trace = {
  /** Goes along as X-Correlation-Id, so one id ties together the logs of every service. */
  readonly correlationId: string;
  readonly log: FastifyBaseLogger;
};

/** The trace of a request: its correlation id and its logger. */
export function traceOf(request: FastifyRequest): Trace {
  return { correlationId: request.id, log: request.log };
}

type Call = {
  readonly method: 'GET' | 'POST';
  readonly path: string;
  readonly body?: unknown;
  readonly idempotencyKey?: string;
};

/** When nothing says otherwise, trying again in this many seconds is a fair bet. */
const RETRY_AFTER_SECONDS = 5;

/** The statuses a gateway or a service uses for "not now": the web may try again later. */
const NOT_NOW = new Set([502, 503, 504]);

/** A service cannot answer now; the problem says when it is worth trying again. */
export class ServiceUnavailable extends DomainError {
  readonly category = 'unavailable';

  constructor(message: string, retryAfterSeconds: number) {
    super(message, { retryAfterSeconds });
  }
}

/** "em 1 segundo", "em 17 segundos". */
export function inSeconds(seconds: number): string {
  return seconds === 1 ? 'em 1 segundo' : `em ${seconds} segundos`;
}

/** What a service answered to one call: its status, and a body read as the contract of the call. */
export class Answer {
  readonly status: number;
  readonly headers: Headers;
  readonly #body: unknown;
  readonly #where: string;

  constructor(where: string, status: number, headers: Headers, body: unknown) {
    this.#where = where;
    this.status = status;
    this.headers = headers;
    this.#body = body;
  }

  fields(): Fields {
    return new Fields(this.#body, this.#where);
  }

  /** The field names of a 422 (`customer.email`), when the service named any. */
  fieldsInError(): string[] {
    const body = this.#body;
    if (typeof body !== 'object' || body === null || !('errors' in body)) {
      return [];
    }
    const { errors } = body;
    return typeof errors === 'object' && errors !== null ? Object.keys(errors) : [];
  }

  /** For a status the call does not expect: the two sides disagree, and a person has to look. */
  unexpected(): UpstreamContractBroken {
    return new UpstreamContractBroken(this.#where, `status ${this.status}`);
  }
}

/**
 * One call to a service: JSON both ways, the correlation id along, and a deadline for
 * the whole exchange, body included. No answer in time, a network error or a "not now"
 * status becomes ServiceUnavailable, a 503 with Retry-After; every other status goes
 * back to the caller, who knows what it means for that call.
 */
export async function call(upstream: Upstream, request: Call, trace: Trace): Promise<Answer> {
  const where = `${upstream.name} ${request.method} ${request.path}`;
  const startedAt = performance.now();
  const headers: Record<string, string> = {
    accept: 'application/json',
    'x-correlation-id': trace.correlationId,
  };
  if (request.body !== undefined) {
    headers['content-type'] = 'application/json';
  }
  if (request.idempotencyKey !== undefined) {
    headers['idempotency-key'] = request.idempotencyKey;
  }

  let status: number;
  let answerHeaders: Headers;
  let text: string;
  try {
    const response = await fetch(`${upstream.baseUrl}${request.path}`, {
      method: request.method,
      headers,
      ...(request.body === undefined ? {} : { body: JSON.stringify(request.body) }),
      signal: AbortSignal.timeout(upstream.timeoutMs),
    });
    status = response.status;
    answerHeaders = response.headers;
    text = await response.text();
  } catch (error) {
    trace.log.warn(
      { upstream: upstream.name, call: where, elapsedMs: elapsedSince(startedAt), err: error },
      `${upstream.name} did not answer`,
    );
    throw notNow(upstream, RETRY_AFTER_SECONDS);
  }

  if (NOT_NOW.has(status)) {
    trace.log.warn(
      { upstream: upstream.name, call: where, status, elapsedMs: elapsedSince(startedAt) },
      `${upstream.name} answered ${status}`,
    );
    throw notNow(upstream, retryAfterOf(answerHeaders) ?? RETRY_AFTER_SECONDS);
  }

  return new Answer(where, status, answerHeaders, bodyOf(where, answerHeaders, text));
}

function notNow(upstream: Upstream, seconds: number): ServiceUnavailable {
  return new ServiceUnavailable(
    `Agora não consegui falar com ${upstream.called}. Tente de novo ${inSeconds(seconds)}.`,
    seconds,
  );
}

function bodyOf(where: string, headers: Headers, text: string): unknown {
  if (text === '' || !(headers.get('content-type') ?? '').includes('json')) {
    return null;
  }
  try {
    return JSON.parse(text);
  } catch {
    throw new UpstreamContractBroken(where, 'a body that is not JSON');
  }
}

/** Retry-After holds seconds or an HTTP date (RFC 9110); anything else is ignored. */
function retryAfterOf(headers: Headers): number | undefined {
  const value = headers.get('retry-after')?.trim();
  if (value === undefined || value === '') {
    return undefined;
  }
  if (/^\d+$/.test(value)) {
    return Number(value);
  }
  const at = Date.parse(value);
  return Number.isNaN(at) ? undefined : Math.max(1, Math.ceil((at - Date.now()) / 1000));
}

function elapsedSince(startedAt: number): number {
  return Math.round(performance.now() - startedAt);
}
