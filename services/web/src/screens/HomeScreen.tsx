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
      <section className="home-hero">
        <div className="home-hero__text">
          {headline !== undefined ? <p className="home-hero__headline">{headline}</p> : null}
          {tagline !== undefined ? <p className="home-hero__tagline">{tagline}</p> : null}
          {catalogLink !== undefined ? (
            <Link link={catalogLink} className="button button--primary home-hero__cta">
              {catalogLink.title ?? catalogLink.href}
            </Link>
          ) : null}
        </div>
        {/* The banner of the brand, the original file from docs/assets: decorative, so it says
            nothing to a screen reader, and sized up front so the page does not jump. */}
        <img
          className="home-hero__art"
          src="/illustrations/banner.png"
          alt=""
          width={1600}
          height={679}
          decoding="async"
          fetchPriority="high"
        />
      </section>
      {trackByCode !== undefined ? (
        <section className="home-screen__tracking" aria-labelledby="home-tracking-heading">
          <h2 id="home-tracking-heading">{trackByCode.title}</h2>
          <ActionForm action={trackByCode} />
        </section>
      ) : null}
    </div>
  );
}
