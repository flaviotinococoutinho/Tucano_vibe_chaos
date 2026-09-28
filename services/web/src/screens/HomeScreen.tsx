import type { ReactElement } from 'react';
import { ActionForm, Link } from '../components/index.ts';
import { findAction, readString, type SirenScreen } from '../siren/index.ts';
import './HomeScreen.css';

export function HomeScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const headline = readString(screen.properties, 'headline');
  const tagline = readString(screen.properties, 'tagline');
  const trackByCode = findAction(screen.actions, 'track-by-code');
  // The one link besides `self`: where the journey starts (see contracts/http/bff/README.md#rel-catalog).
  const catalogLink = screen.links.find((link) => !link.rel.includes('self'));

  return (
    <div className="home-screen">
      {headline !== undefined ? <p className="home-screen__headline">{headline}</p> : null}
      {tagline !== undefined ? <p className="home-screen__tagline">{tagline}</p> : null}
      {catalogLink !== undefined ? (
        <Link link={catalogLink} className="button button--primary home-screen__cta">
          {catalogLink.title ?? catalogLink.href}
        </Link>
      ) : null}
      {trackByCode !== undefined ? (
        <section className="home-screen__tracking" aria-labelledby="home-tracking-heading">
          <h2 id="home-tracking-heading">{trackByCode.title}</h2>
          <ActionForm action={trackByCode} />
        </section>
      ) : null}
    </div>
  );
}
