import { type ReactElement, type RefObject, useEffect, useRef } from 'react';
import {
  LiveRegion,
  Notice,
  ProblemView,
  ProgressBar,
  SkeletonScreen,
} from '../components/index.ts';
import { type Problem, useHypermedia } from '../hypermedia/index.ts';
import { readNotice, type SirenScreen } from '../siren/index.ts';
import { componentFor } from './registry.ts';
import './ScreenRouter.css';

/**
 * The interpreter's front door: picks a component by the current screen's class (falling
 * back to `GenericScreen`), keeps the previous screen visible while the next one loads,
 * and owns the two things every screen shares regardless of its class: the heading (and
 * moving focus to it after a real navigation, never after a live refresh) and a
 * `properties.notice`, when the screen carries one.
 */
export function ScreenRouter(): ReactElement {
  const { screen, browserPath, isFirstLoad, isNavigating, problem, announcement } = useHypermedia();
  const headingRef = useRef<HTMLHeadingElement | null>(null);
  const lastFocusedPath = useRef<string | null>(null);

  // No dependency array on purpose: it runs after every render, but only acts once a heading
  // for a *new* path has actually mounted (a live refresh never changes browserPath).
  useEffect(() => {
    if (headingRef.current !== null && browserPath !== lastFocusedPath.current) {
      lastFocusedPath.current = browserPath;
      headingRef.current.focus();
    }
  });

  return (
    <>
      <ProgressBar active={isNavigating} />
      <LiveRegion message={announcement} />
      <main id="main-content" className="container screen-router">
        {isFirstLoad ? (
          <SkeletonScreen />
        ) : problem !== null ? (
          <ProblemScreen headingRef={headingRef} title={problemTitle(problem)} problem={problem} />
        ) : screen !== null ? (
          <ScreenBody screen={screen} headingRef={headingRef} />
        ) : null}
      </main>
    </>
  );
}

function problemTitle(problem: Problem): string {
  return problem.kind === 'http' ? problem.title : 'Falha de conexão';
}

function ProblemScreen({
  headingRef,
  title,
  problem,
}: {
  readonly headingRef: RefObject<HTMLHeadingElement | null>;
  readonly title: string;
  readonly problem: Problem;
}): ReactElement {
  return (
    <>
      <h1 ref={headingRef} tabIndex={-1}>
        {title}
      </h1>
      <ProblemView problem={problem} variant="page" />
    </>
  );
}

function ScreenBody({
  screen,
  headingRef,
}: {
  readonly screen: SirenScreen;
  readonly headingRef: RefObject<HTMLHeadingElement | null>;
}): ReactElement {
  const notice = readNotice(screen.properties);
  const Component = componentFor(screen);

  return (
    <>
      <h1 ref={headingRef} tabIndex={-1}>
        {screen.title}
      </h1>
      {notice !== undefined ? <Notice notice={notice} /> : null}
      <Component screen={screen} />
    </>
  );
}
