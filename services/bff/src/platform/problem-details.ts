import { STATUS_CODES } from 'node:http';
import type { FastifyReply, FastifyRequest, FastifySchemaValidationError } from 'fastify';
import { DomainError, type ErrorCategory } from './domain-error.ts';

/** RFC 9457 body, with exactly the fields the PHP services send. */
type ProblemDetails = {
  readonly type: 'about:blank';
  readonly title: string;
  readonly status: number;
  readonly detail: string;
  readonly instance: string;
  readonly correlationId: string;
  readonly errors?: Readonly<Record<string, readonly string[]>>;
};

type ValidationFailure = Error & {
  readonly validation: readonly FastifySchemaValidationError[];
  readonly validationContext?: string;
};

const PROBLEM_JSON = 'application/problem+json';

const HIDDEN_DETAIL =
  'Something went wrong on our side. Quote the correlation id when reporting it.';

const STATUS_BY_CATEGORY = {
  not_found: 404,
  conflict: 409,
  invalid_input: 422,
  forbidden: 403,
  unavailable: 503,
} as const satisfies Record<ErrorCategory, number>;

// RFC 9110 renamed these two. Node still ships the old phrases, while the PHP services
// already send the new ones.
const RFC_9110_TITLES: Readonly<Record<number, string>> = {
  413: 'Content Too Large',
  422: 'Unprocessable Content',
};

/** Error handler: every error leaves the service as problem details. */
export function sendProblem(
  error: unknown,
  request: FastifyRequest,
  reply: FastifyReply,
): FastifyReply {
  const status = statusOf(error);
  // Domain errors are answers the caller can act on, not incidents; even a 503 says what to do.
  const incident = status >= 500 && !(error instanceof DomainError);
  if (incident) {
    request.log.error({ req: request, err: error }, messageOf(error));
  }
  const problem = problemOf(status, incident ? HIDDEN_DETAIL : messageOf(error), request);
  const errors = isValidationFailure(error) ? fieldErrors(error) : fieldErrorsOf(error);
  const body = errors === undefined ? problem : { ...problem, errors };
  if (error instanceof DomainError && error.retryAfterSeconds !== undefined) {
    reply.header('retry-after', String(error.retryAfterSeconds));
  }

  return reply.code(status).type(PROBLEM_JSON).send(body);
}

/** Not-found handler: unknown routes answer with problem details too. */
export function sendNotFound(request: FastifyRequest, reply: FastifyReply): FastifyReply {
  const detail = `The route ${request.method} ${request.url} could not be found.`;
  const problem = problemOf(404, detail, request);

  return reply.code(404).type(PROBLEM_JSON).send(problem);
}

function problemOf(status: number, detail: string, request: FastifyRequest): ProblemDetails {
  return {
    type: 'about:blank',
    title: RFC_9110_TITLES[status] ?? STATUS_CODES[status] ?? 'Error',
    status,
    detail,
    instance: request.url,
    correlationId: request.id,
  };
}

function statusOf(error: unknown): number {
  if (isValidationFailure(error)) {
    return 422;
  }
  if (error instanceof DomainError) {
    return STATUS_BY_CATEGORY[error.category];
  }
  // Fastify's own errors (malformed JSON, body too large) carry the status they stand for.
  if (error instanceof Error && 'statusCode' in error && isErrorStatus(error.statusCode)) {
    return error.statusCode;
  }
  return 500;
}

function isErrorStatus(status: unknown): status is number {
  return typeof status === 'number' && status >= 400 && status <= 599;
}

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

function isValidationFailure(error: unknown): error is ValidationFailure {
  return error instanceof Error && 'validation' in error && Array.isArray(error.validation);
}

/** The messages a domain error keeps per field, when it keeps any. */
function fieldErrorsOf(error: unknown): Readonly<Record<string, readonly string[]>> | undefined {
  return error instanceof DomainError ? error.fieldErrors : undefined;
}

/** `{ "sku": ["must be string"] }`, the same shape Laravel gives the PHP services. */
function fieldErrors({
  validation,
  validationContext = 'request',
}: ValidationFailure): Record<string, string[]> {
  const errors: Record<string, string[]> = {};
  for (const issue of validation) {
    const field = fieldOf(issue) || validationContext;
    errors[field] ??= [];
    errors[field].push(issue.message ?? 'is invalid');
  }
  return errors;
}

/** `/items/0/sku` becomes `items.0.sku`, and a missing property is named after itself. */
function fieldOf({ instancePath, params }: FastifySchemaValidationError): string {
  const path = instancePath.split('/').filter((segment) => segment !== '');
  if (typeof params.missingProperty === 'string') {
    path.push(params.missingProperty);
  }
  return path.join('.');
}
