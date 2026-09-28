/**
 * A minimal stand-in for the `fetch` `Response` the client code actually reads
 * (`ok`, `status`, `statusText`, `url`, `headers.get`, `json`, `clone`). The real `Response`
 * class ignores a constructor `url`, which every navigation test here needs to control.
 */
export function fakeResponse(options: {
  readonly status?: number;
  readonly url?: string;
  readonly body?: unknown;
  readonly headers?: Readonly<Record<string, string>>;
}): Response {
  const status = options.status ?? 200;
  const headers = new Headers(options.headers ?? {});
  const text = JSON.stringify(options.body ?? {});

  const response = {
    ok: status >= 200 && status < 300,
    status,
    statusText: '',
    url: options.url ?? '',
    headers,
    json: () => Promise.resolve(JSON.parse(text)),
    clone(): Response {
      return response as unknown as Response;
    },
  };
  return response as unknown as Response;
}
