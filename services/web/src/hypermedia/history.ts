import { BrowserPath } from './ids.ts';

/** Thin, side-effecting wrappers over the History API. Nothing here decides what to fetch. */

export function currentBrowserPath(): BrowserPath {
  const { pathname, search, hash } = window.location;
  return BrowserPath.of(`${pathname}${search}${hash}`);
}

export function pushBrowserPath(path: BrowserPath): void {
  window.history.pushState(null, '', path);
}

export function replaceBrowserPath(path: BrowserPath): void {
  window.history.replaceState(null, '', path);
}

/** Calls `listener` with the new path on every back/forward. Returns the unsubscribe function. */
export function onPopState(listener: (path: BrowserPath) => void): () => void {
  const handler = () => listener(currentBrowserPath());
  window.addEventListener('popstate', handler);
  return () => window.removeEventListener('popstate', handler);
}
