import type { ReactElement } from 'react';
import { ActionForm, Link, Monogram } from '../components/index.ts';
import { findAction, findLink, REL, readString, type SirenScreen } from '../siren/index.ts';
import { paletteOf } from '../theme/index.ts';
import './StoreScreen.css';

/** The home of a store: its sign, its tagline, the way into its catalog and tracking by code. */
export function StoreScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const tagline = readString(screen.properties, 'tagline');
  const initial = readString(screen.properties, 'initial');
  const palette = paletteOf(screen.properties?.palette);
  const catalog = findLink(screen.links, REL.catalog);
  const trackByCode = findAction(screen.actions, 'track-by-code');

  return (
    <div className="store-screen">
      <section className="store-hero" data-palette={palette}>
        {initial !== undefined ? (
          <Monogram initial={initial} palette={palette} size="large" />
        ) : null}
        <div className="store-hero__text">
          {tagline !== undefined ? <p className="store-hero__tagline">{tagline}</p> : null}
          {catalog !== undefined ? (
            <Link link={catalog} className="button button--primary">
              {catalog.title ?? catalog.href}
            </Link>
          ) : null}
        </div>
      </section>
      {trackByCode !== undefined ? (
        <section className="store-screen__tracking" aria-labelledby="store-tracking-heading">
          <h2 id="store-tracking-heading">{trackByCode.title}</h2>
          <ActionForm action={trackByCode} />
        </section>
      ) : null}
    </div>
  );
}
