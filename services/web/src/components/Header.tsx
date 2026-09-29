import { type ReactElement, useEffect, useState } from 'react';
import { BrowserPath, toBffHref, toBrowserPath, useHypermedia } from '../hypermedia/index.ts';
import {
  findEntity,
  findLink,
  REL,
  readShopper,
  type SirenLink,
  type SirenSubEntity,
} from '../siren/index.ts';
import { Avatar } from './Avatar.tsx';
import './Header.css';
import { Link } from './Link.tsx';
import { ThemeToggle } from './ThemeToggle.tsx';

// The header's own link to the entry screen. Built with the same toBffHref every other
// link travels through, so `Link` never has to special-case it.
const HOME_LINK: SirenLink = { rel: ['home'], href: toBffHref(BrowserPath.of('/')) };

/**
 * The brand, the navigation of the screen on show and the theme toggle. The links and the
 * shopper come from the `navigation` component the BFF embeds in every screen, so the header
 * changes with the screen and keeps no menu of its own. A problem has no navigation, and the
 * session behind the last screen did not change because of it, so the header keeps that
 * screen's links: a 404 that says "troque de perfil" leaves the way to the profiles in sight.
 * A problem on the very first screen leaves only the brand and the toggle.
 */
export function Header(): ReactElement {
  const { screen, browserPath, problem } = useHypermedia();
  const current = findEntity(screen?.entities, REL.navigation);
  const [lastSeen, setLastSeen] = useState<SirenSubEntity | undefined>(undefined);
  useEffect(() => {
    if (current !== undefined) {
      setLastSeen(current);
    }
  }, [current]);
  const navigation = current ?? (problem !== null ? lastSeen : undefined);

  return (
    <header className="app-header">
      <div className="container app-header__inner">
        {/* The name next to the logo already names the link, so the logo says nothing twice. */}
        <Link link={HOME_LINK} className="app-header__brand">
          <img src="/logo-256.png" alt="" width={40} height={40} />
          <span className="app-header__title">Tucano</span>
        </Link>
        {navigation !== undefined ? (
          <SiteNavigation navigation={navigation} browserPath={browserPath} />
        ) : null}
        <div className="app-header__toggle">
          <ThemeToggle />
        </div>
      </div>
    </header>
  );
}

function SiteNavigation({
  navigation,
  browserPath,
}: {
  readonly navigation: SirenSubEntity;
  readonly browserPath: BrowserPath;
}): ReactElement {
  const catalog = findLink(navigation.links, REL.catalog);
  const orders = findLink(navigation.links, REL.orders);
  const profiles = findLink(navigation.links, REL.profiles);
  const shopper = readShopper(navigation.properties);

  return (
    <nav className="app-header__nav" aria-label="Principal">
      <ul className="app-nav">
        {[catalog, orders].map((link) =>
          link !== undefined ? (
            <li key={link.href}>
              <Link
                link={link}
                className="app-nav__link"
                aria-current={currentness(link, browserPath)}
              />
            </li>
          ) : null,
        )}
        {profiles !== undefined ? (
          <li>
            <Link
              link={profiles}
              className={`profile-chip${shopper ? '' : ' profile-chip--nobody'}`}
              aria-current={currentness(profiles, browserPath)}
            >
              {shopper ? (
                <>
                  <Avatar initial={shopper.initial} profileId={shopper.profileId} />
                  <span className="visually-hidden">Perfil:</span>{' '}
                  <span className="profile-chip__label">{profiles.title ?? shopper.label}</span>
                </>
              ) : (
                (profiles.title ?? profiles.href)
              )}
            </Link>
          </li>
        ) : null}
      </ul>
    </nav>
  );
}

/**
 * Whether a link of the header is where the reader is: the page itself (`page`), or a page
 * inside its section, like an order under "Meus pedidos" (`true`, as WAI-ARIA reads it).
 * Only addresses the server handed out are compared; the web builds none.
 */
function currentness(link: SirenLink, browserPath: BrowserPath): 'page' | 'true' | undefined {
  const section = pathOnly(toBrowserPath(link.href));
  const here = pathOnly(browserPath);
  if (here === section) {
    return 'page';
  }
  return here.startsWith(`${section}/`) ? 'true' : undefined;
}

function pathOnly(path: string): string {
  return path.split(/[?#]/)[0] ?? path;
}
