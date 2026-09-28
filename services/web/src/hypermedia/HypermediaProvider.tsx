import {
  createContext,
  type ReactElement,
  type ReactNode,
  useCallback,
  useContext,
  useEffect,
  useReducer,
  useRef,
  useState,
} from 'react';
import {
  findLink,
  isLive,
  readNumber,
  readString,
  type SirenAction,
  type SirenScreen,
} from '../siren/index.ts';
import {
  type FieldValues,
  fetchScreen,
  type SubmitOutcome,
  submitAction as submitActionRequest,
} from './client.ts';
import { currentBrowserPath, onPopState, pushBrowserPath, replaceBrowserPath } from './history.ts';
import { BffHref, type BrowserPath } from './ids.ts';
import { toBffHref } from './prefix.ts';
import { isProblem, networkProblem, type Problem } from './problem.ts';

type State =
  | { readonly status: 'loading'; readonly screen: null; readonly browserPath: BrowserPath }
  | {
      readonly status: 'ready';
      readonly screen: SirenScreen;
      readonly browserPath: BrowserPath;
      readonly isNavigating: boolean;
    }
  | { readonly status: 'error'; readonly problem: Problem; readonly browserPath: BrowserPath };

type Event =
  | { readonly type: 'start' }
  | { readonly type: 'settle' }
  | { readonly type: 'success'; readonly screen: SirenScreen; readonly browserPath: BrowserPath }
  | { readonly type: 'failure'; readonly problem: Problem; readonly browserPath: BrowserPath }
  | { readonly type: 'live-update'; readonly screen: SirenScreen };

function reducer(state: State, event: Event): State {
  switch (event.type) {
    case 'start':
      return state.status === 'ready' ? { ...state, isNavigating: true } : state;
    case 'settle':
      return state.status === 'ready' ? { ...state, isNavigating: false } : state;
    case 'success':
      return {
        status: 'ready',
        screen: event.screen,
        browserPath: event.browserPath,
        isNavigating: false,
      };
    case 'failure':
      return { status: 'error', problem: event.problem, browserPath: event.browserPath };
    case 'live-update':
      return state.status === 'ready' ? { ...state, screen: event.screen } : state;
  }
}

export type HypermediaState = {
  /** `null` only before the very first screen has arrived. */
  readonly screen: SirenScreen | null;
  readonly browserPath: BrowserPath;
  /** True only for the first-ever load: the caller shows a skeleton, not the previous screen. */
  readonly isFirstLoad: boolean;
  /** True while a later navigation or submission is in flight; the previous screen stays put. */
  readonly isNavigating: boolean;
  readonly problem: Problem | null;
  /** Text for an `aria-live="polite"` region: set when a live screen's status label changes. */
  readonly announcement: string;
  /** Follows a link's (already browser-mapped) path: pushes history once the screen resolves. */
  readonly navigate: (path: BrowserPath) => void;
  /** Submits an action's fields. Resolves; it never throws, and never navigates on its own for a 422 or a problem. */
  readonly submitAction: (action: SirenAction, values: FieldValues) => Promise<SubmitOutcome>;
};

const HypermediaContext = createContext<HypermediaState | undefined>(undefined);

export function HypermediaProvider({ children }: { readonly children: ReactNode }): ReactElement {
  const [state, dispatch] = useReducer(
    reducer,
    currentBrowserPath(),
    (browserPath): State => ({ status: 'loading', screen: null, browserPath }),
  );
  const [announcement, setAnnouncement] = useState('');
  // Guards against an in-flight fetch resolving after a newer navigation already started.
  const requestId = useRef(0);

  const load = useCallback(
    (href: BffHref, requestedPath: BrowserPath, history: 'push' | 'sync') => {
      const id = ++requestId.current;
      dispatch({ type: 'start' });
      fetchScreen(href)
        .then(({ screen, browserPath }) => {
          if (id !== requestId.current) {
            return;
          }
          if (history === 'push') {
            pushBrowserPath(browserPath);
          } else if (browserPath !== requestedPath) {
            replaceBrowserPath(browserPath);
          }
          dispatch({ type: 'success', screen, browserPath });
        })
        .catch((error: unknown) => {
          if (id !== requestId.current) {
            return;
          }
          dispatch({
            type: 'failure',
            problem: isProblem(error) ? error : networkProblem(error),
            browserPath: requestedPath,
          });
        });
    },
    [],
  );

  const navigate = useCallback(
    (path: BrowserPath) => {
      load(toBffHref(path), path, 'push');
    },
    [load],
  );

  const submit = useCallback(async (action: SirenAction, values: FieldValues) => {
    dispatch({ type: 'start' });
    const outcome = await submitActionRequest(action, values);
    if (outcome.kind === 'navigated') {
      requestId.current += 1;
      pushBrowserPath(outcome.browserPath);
      dispatch({ type: 'success', screen: outcome.screen, browserPath: outcome.browserPath });
    } else {
      dispatch({ type: 'settle' });
    }
    return outcome;
  }, []);

  // The first screen, and every back/forward the visitor makes afterwards. `load` is left out
  // of the dependency list on purpose: it is referentially stable, and this effect must run
  // exactly once, not on every render that happens to create a new `load` closure identity.
  // biome-ignore lint/correctness/useExhaustiveDependencies: load is referentially stable.
  useEffect(() => {
    const path = currentBrowserPath();
    load(toBffHref(path), path, 'sync');
    return onPopState((next) => load(toBffHref(next), next, 'sync'));
  }, []);

  const liveScreen = state.status === 'ready' ? state.screen : null;

  // Live screens: refetch `self` after `refreshAfterSeconds`, paused while the tab is hidden,
  // and only for as long as the currently shown screen still asks for it. Keyed on the screen
  // object itself (not the whole state) so a navigation's `isNavigating` flicker never restarts it.
  useEffect(() => {
    const screen = liveScreen;
    if (screen === null || !isLive(screen)) {
      return;
    }
    const seconds = readNumber(screen.properties, 'refreshAfterSeconds');
    const selfHref = findLink(screen.links, 'self')?.href;
    if (seconds === undefined || selfHref === undefined) {
      return;
    }

    let cancelled = false;
    let timer: ReturnType<typeof setTimeout>;

    const schedule = () => {
      timer = setTimeout(tick, seconds * 1000);
    };
    const tick = () => {
      if (cancelled) {
        return;
      }
      if (document.hidden) {
        schedule();
        return;
      }
      fetchScreen(BffHref.of(selfHref))
        .then(({ screen: next }) => {
          if (cancelled) {
            return;
          }
          const previousLabel = readString(screen.properties, 'statusLabel');
          const nextLabel = readString(next.properties, 'statusLabel');
          if (nextLabel !== undefined && nextLabel !== previousLabel) {
            setAnnouncement(nextLabel);
          }
          dispatch({ type: 'live-update', screen: next });
          schedule();
        })
        .catch(() => {
          // A live refresh that fails is not the end of the world: it tries again next tick.
          if (!cancelled) {
            schedule();
          }
        });
    };
    const onVisibilityChange = () => {
      if (!document.hidden) {
        clearTimeout(timer);
        tick();
      }
    };

    document.addEventListener('visibilitychange', onVisibilityChange);
    schedule();
    return () => {
      cancelled = true;
      clearTimeout(timer);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [liveScreen]);

  const value: HypermediaState = {
    screen: state.status === 'error' ? null : state.screen,
    browserPath: state.browserPath,
    isFirstLoad: state.status === 'loading',
    isNavigating: state.status === 'ready' && state.isNavigating,
    problem: state.status === 'error' ? state.problem : null,
    announcement,
    navigate,
    submitAction: submit,
  };

  return <HypermediaContext.Provider value={value}>{children}</HypermediaContext.Provider>;
}

export function useHypermedia(): HypermediaState {
  const value = useContext(HypermediaContext);
  if (value === undefined) {
    throw new Error('useHypermedia must be used within a HypermediaProvider');
  }
  return value;
}
