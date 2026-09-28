import type { ReactElement } from 'react';
import { BrowserPath, toBffHref } from '../hypermedia/index.ts';
import type { SirenLink } from '../siren/index.ts';
import './Header.css';
import { Link } from './Link.tsx';
import { ThemeToggle } from './ThemeToggle.tsx';

// The header's own link to the entry screen. Built with the same toBffHref every other
// link travels through, so `Link` never has to special-case it.
const HOME_LINK: SirenLink = { rel: ['home'], href: toBffHref(BrowserPath.of('/')) };

export function Header(): ReactElement {
  return (
    <header className="app-header">
      <div className="container app-header__inner">
        <Link link={HOME_LINK} className="app-header__brand">
          <img src="/logo-256.png" alt="Tucano" width={40} height={40} />
          <span className="app-header__title">Tucano</span>
        </Link>
        <ThemeToggle />
      </div>
    </header>
  );
}
