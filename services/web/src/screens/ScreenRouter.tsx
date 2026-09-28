import { type ReactElement, type RefObject, useEffect, useRef } from 'react';
import {
  Link,
  LiveRegion,
  Notice,
  ProblemView,
  ProgressBar,
  SkeletonScreen,
} from '../components/index.ts';
import { type Problem, useHypermedia } from '../hypermedia/index.ts';
import { findLink, readNotice, type SirenLink, type SirenScreen } from '../siren/index.ts';
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
  // for a *new* path has actually mounted (a live refresh never changes browserPath). The first
  // screen keeps the focus where the browser puts it: nobody navigated yet, the page just opened.
  useEffect(() => {
    if (headingRef.current === null || browserPath === lastFocusedPath.current) {
      return;
    }
    const firstScreen = lastFocusedPath.current === null;
    lastFocusedPath.current = browserPath;
    if (!firstScreen) {
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

/**
 * Where "back" goes, when the screen says: the collection it came from (the catalog, for a
 * product) before the screen above it (the start). Both are RFC 8288 relations the contract
 * uses, so every screen that has one gets the same way back without a line of its own.
 */
function wayBack(screen: SirenScreen): SirenLink | undefined {
  return findLink(screen.links, 'collection') ?? findLink(screen.links, 'up');
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
  const back = wayBack(screen);

  return (
    <>
      {back !== undefined ? (
        <nav className="screen-router__back" aria-label="Voltar">
          <Link link={back} />
        </nav>
      ) : null}
      <h1 ref={headingRef} tabIndex={-1}>
        {screen.title}
      </h1>
      {notice !== undefined ? <Notice notice={notice} /> : null}
      <Component screen={screen} />
    </>
  );
}
