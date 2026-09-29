import type { ReactElement } from 'react';
import { ActionForm, StoreCard } from '../components/index.ts';
import {
  entitiesOf,
  findAction,
  findLink,
  hasClass,
  readString,
  type SirenScreen,
} from '../siren/index.ts';
import './HomeScreen.css';

/** The home of the platform: the brand of Tucano, the stores it hosts, and tracking by code. */
export function HomeScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const headline = readString(screen.properties, 'headline');
  const tagline = readString(screen.properties, 'tagline');
  const stores = entitiesOf(screen, 'item').filter((entity) => hasClass(entity, 'store-card'));
  const trackByCode = findAction(screen.actions, 'track-by-code');

  return (
    <div className="home-screen">
      <section className="home-hero">
        <div className="home-hero__text">
          {headline !== undefined ? <p className="home-hero__headline">{headline}</p> : null}
          {tagline !== undefined ? <p className="home-hero__tagline">{tagline}</p> : null}
        </div>
        {/* The banner of the brand, derived from docs/assets by `make web-art`: decorative, so
            it says nothing to a screen reader, and sized up front so the page does not jump. */}
        <img
          className="home-hero__art"
          src="/illustrations/banner.webp"
          alt=""
          width={1600}
          height={686}
          decoding="async"
          fetchPriority="high"
        />
      </section>
      {stores.length > 0 ? (
        <section className="home-screen__stores" aria-labelledby="home-stores-heading">
          <h2 id="home-stores-heading">Lojas</h2>
          <ul className="home-screen__store-list">
            {stores.map((entity, index) => (
              <StoreCard
                entity={entity}
                key={
                  readString(entity.properties, 'slug') ??
                  findLink(entity.links, 'self')?.href ??
                  index
                }
              />
            ))}
          </ul>
        </section>
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
