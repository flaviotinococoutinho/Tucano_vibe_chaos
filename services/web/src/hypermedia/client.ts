import type { SirenAction, SirenScreen } from '../siren/index.ts';
import { BffHref, type BrowserPath } from './ids.ts';
import { toBrowserPath } from './prefix.ts';
import {
  type HttpProblem,
  isProblem,
  isValidationProblem,
  networkProblem,
  type Problem,
  problemFromResponse,
} from './problem.ts';

/**
 * The one module in the app that calls `fetch`. Everything else asks it for a screen or
 * submits an action through it, and never touches the network directly.
 */

const SIREN_ACCEPT = 'application/vnd.siren+json';

/** A field value ready to travel: hidden fields keep the type the BFF gave them. */
export type FieldValues = Readonly<Record<string, string | number>>;

export type ScreenResult = {
  readonly screen: SirenScreen;
  readonly browserPath: BrowserPath;
};

export type SubmitOutcome =
  | ({ readonly kind: 'navigated' } & ScreenResult)
  | { readonly kind: 'validation'; readonly problem: ValidationProblem }
  | { readonly kind: 'problem'; readonly problem: Problem };

export type ValidationProblem = HttpProblem & {
  readonly errors: Readonly<Record<string, readonly string[]>>;
};

/** GET, follow the Siren envelope of `href`. `fetch` follows any 303 on its own. */
export async function fetchScreen(href: BffHref, signal?: AbortSignal): Promise<ScreenResult> {
  const response = await get(href, signal);
  if (!response.ok) {
    throw await problemFromResponse(response);
  }
  return toScreenResult(response);
}

/**
 * Submits an action's fields. A GET action is a navigation with the fields as query string;
 * a mutation (POST) sends JSON and, on 201/202, the response body already is the next
 * screen, addressed by its `Location` header. Either way the caller gets back a screen to
 * show or a problem to report, never a thrown exception.
 */
export async function submitAction(
  action: SirenAction,
  values: FieldValues,
): Promise<SubmitOutcome> {
  try {
    const result =
      action.method === 'GET'
        ? await submitGet(action, values)
        : await submitMutation(action, values);
    return { kind: 'navigated', ...result };
  } catch (error) {
    const problem = isProblem(error) ? error : networkProblem(error);
    return isValidationProblem(problem)
      ? { kind: 'validation', problem }
      : { kind: 'problem', problem };
  }
}

async function submitGet(action: SirenAction, values: FieldValues): Promise<ScreenResult> {
  const response = await get(BffHref.of(hrefWithQuery(action.href, values)));
  if (!response.ok) {
    throw await problemFromResponse(response);
  }
  return toScreenResult(response);
}

async function submitMutation(action: SirenAction, values: FieldValues): Promise<ScreenResult> {
  let response: Response;
  try {
    response = await fetch(action.href, {
      method: action.method,
      headers: { 'Content-Type': 'application/json', Accept: SIREN_ACCEPT },
      // The guest checkout id travels in an HttpOnly cookie the BFF sets; same-origin
      // (fetch's own default) is what lets the browser send and keep it.
      credentials: 'same-origin',
      body: JSON.stringify(values),
    });
  } catch (error) {
    throw networkProblem(error);
  }

  if (!response.ok) {
    throw await problemFromResponse(response);
  }

  const screen = (await response.json()) as SirenScreen;
  const location = response.headers.get('Location');
  const browserPath = toBrowserPath(location ?? response.url);
  return { screen, browserPath };
}

async function get(href: BffHref, signal?: AbortSignal): Promise<Response> {
  try {
    return await fetch(href, {
      method: 'GET',
      headers: { Accept: SIREN_ACCEPT },
      credentials: 'same-origin',
      ...(signal ? { signal } : {}),
    });
  } catch (error) {
    throw networkProblem(error);
  }
}

async function toScreenResult(response: Response): Promise<ScreenResult> {
  const screen = (await response.json()) as SirenScreen;
  // A GET may have been redirected (the 303 of track-by-code); response.url is final either way.
  return { screen, browserPath: toBrowserPath(response.url) };
}

function hrefWithQuery(href: string, values: FieldValues): string {
  const url = new URL(href, window.location.origin);
  for (const [name, value] of Object.entries(values)) {
    if (value !== '') {
      url.searchParams.set(name, String(value));
    }
  }
  return `${url.pathname}${url.search}`;
}
