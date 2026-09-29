import { type ReactElement, useEffect, useState } from 'react';
import { BrowserPath, toBffHref, toBrowserPath, useHypermedia } from '../hypermedia/index.ts';
import {
  findEntity,
  findLink,
  REL,
  readShopper,
  readStore,
  type SirenLink,
  type SirenSubEntity,
  type StoreBrand,
} from '../siren/index.ts';
import { paletteOf, usePagePalette } from '../theme/index.ts';
import { Avatar } from './Avatar.tsx';
import './Header.css';
import { Link } from './Link.tsx';
import { Monogram } from './Monogram.tsx';
import { ThemeToggle } from './ThemeToggle.tsx';

// The header's own link to the entry screen, for a page with no navigation to take it from (a
// problem at the very first screen). Built with the same toBffHref every other link travels
// through, so `Link` never has to special-case it.
const HOME_LINK: SirenLink = { rel: ['home'], href: toBffHref(BrowserPath.of('/')) };

/**
 * The brand, the navigation of the screen on show and the theme toggle. The links, the shopper
 * and the store come from the `navigation` component the BFF embeds in every screen, so the
 * header changes with the screen and keeps no menu of its own. Inside a store the store is the
 * brand, with a quiet way back to all the stores, and the page wears the palette of the store;
 * on the screens of the platform the brand is Tucano. A problem has no navigation, and neither
 * the session nor the store behind the last screen changed because of it, so the header keeps
 * that screen's navigation and its palette: a 404 that says "troque de perfil" leaves the way
 * to the profiles in sight, in the store the person was in. A problem on the very first screen
 * leaves only the brand of the platform and the toggle.
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
  const store = readStore(navigation?.properties) ?? null;
  const storeHome = findLink(navigation?.links, REL.store);
  const allStores = findLink(navigation?.links, REL.stores);
  usePagePalette(store === null ? undefined : paletteOf(store.palette));

  return (
    <header className={store === null ? 'app-header' : 'app-header app-header--store'}>
      <div className="container app-header__inner">
        {store !== null && storeHome !== undefined ? (
          <StoreBrandLinks store={store} home={storeHome} allStores={allStores} />
        ) : (
          // The name next to the logo already names the link, so the logo says nothing twice.
          <Link link={allStores ?? HOME_LINK} className="app-header__brand">
            <img src="/logo-256.png" alt="" width={40} height={40} />
            <span className="app-header__title">Tucano</span>
          </Link>
        )}
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

/** The store as the brand, its monogram and its name leading to its home, and all the stores below. */
function StoreBrandLinks({
  store,
  home,
  allStores,
}: {
  readonly store: StoreBrand;
  readonly home: SirenLink;
  readonly allStores: SirenLink | undefined;
}): ReactElement {
  return (
    <div className="app-header__brand app-header__brand--store">
      <Link link={home} className="store-brand">
        <Monogram initial={store.initial} palette={paletteOf(store.palette)} />
        <span className="store-brand__name">{store.name}</span>
      </Link>
      {allStores !== undefined ? <Link link={allStores} className="app-header__platform" /> : null}
    </div>
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
