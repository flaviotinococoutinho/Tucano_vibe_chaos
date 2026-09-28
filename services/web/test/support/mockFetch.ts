import { vi } from 'vitest';

/** Stubs `fetch` for the rest of the test; `restoreMocks` (vite.config.ts) undoes it afterwards. */
export function mockFetchSequence(responses: readonly Response[]): void {
  const spy = vi.spyOn(globalThis, 'fetch');
  for (const response of responses) {
    spy.mockResolvedValueOnce(response);
  }
}

export function mockFetchAlways(response: Response): void {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(response);
}
