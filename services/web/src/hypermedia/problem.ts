/**
 * RFC 9457 (`application/problem+json`) as the BFF sends it, plus the one shape it does not
 * standardize: a network failure that never reached the server at all. `components/ProblemView`
 * turns either into something a person reads; this module only carries the facts.
 */
export type HttpProblem = {
  readonly kind: 'http';
  readonly status: number;
  readonly title: string;
  readonly detail: string;
  readonly correlationId?: string;
  readonly retryAfterSeconds?: number;
  readonly errors?: Readonly<Record<string, readonly string[]>>;
};

/** The request never reached the BFF: offline, DNS, a dropped connection. */
export type NetworkProblem = {
  readonly kind: 'network';
  readonly message: string;
};

export type Problem = HttpProblem | NetworkProblem;

/** Narrows a value caught from a `throw` back to a `Problem` the client raised on purpose. */
export function isProblem(value: unknown): value is Problem {
  return (
    typeof value === 'object' &&
    value !== null &&
    'kind' in value &&
    (value.kind === 'http' || value.kind === 'network')
  );
}

export function isValidationProblem(
  problem: Problem,
): problem is HttpProblem & { readonly errors: Readonly<Record<string, readonly string[]>> } {
  return problem.kind === 'http' && problem.status === 422 && problem.errors !== undefined;
}

/** Builds a `Problem` from a non-OK `Response`. Never throws: a body that is not problem+json still yields a usable problem. */
export async function problemFromResponse(response: Response): Promise<HttpProblem> {
  const body = await readJson(response);
  const retryAfterSeconds = parseRetryAfter(response.headers.get('Retry-After'));

  if (isProblemBody(body)) {
    return {
      kind: 'http',
      status: typeof body.status === 'number' ? body.status : response.status,
      title: typeof body.title === 'string' ? body.title : response.statusText,
      detail: typeof body.detail === 'string' ? body.detail : response.statusText,
      ...(typeof body.correlationId === 'string' ? { correlationId: body.correlationId } : {}),
      ...(retryAfterSeconds !== undefined ? { retryAfterSeconds } : {}),
      ...(isErrorsMap(body.errors) ? { errors: body.errors } : {}),
    };
  }

  return {
    kind: 'http',
    status: response.status,
    title: response.statusText || 'Error',
    detail: response.statusText || `Request failed with status ${response.status}`,
    ...(retryAfterSeconds !== undefined ? { retryAfterSeconds } : {}),
  };
}

export function networkProblem(error: unknown): NetworkProblem {
  return {
    kind: 'network',
    message: error instanceof Error ? error.message : String(error),
  };
}

async function readJson(response: Response): Promise<unknown> {
  try {
    return await response.clone().json();
  } catch {
    return undefined;
  }
}

function isProblemBody(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function isErrorsMap(value: unknown): value is Readonly<Record<string, readonly string[]>> {
  return (
    typeof value === 'object' &&
    value !== null &&
    !Array.isArray(value) &&
    Object.values(value).every(
      (messages) => Array.isArray(messages) && messages.every((m) => typeof m === 'string'),
    )
  );
}

function parseRetryAfter(header: string | null): number | undefined {
  if (header === null) {
    return undefined;
  }
  const seconds = Number(header);
  return Number.isFinite(seconds) && seconds >= 0 ? seconds : undefined;
}
