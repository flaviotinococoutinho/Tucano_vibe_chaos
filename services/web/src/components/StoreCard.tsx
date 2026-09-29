import type { ReactElement } from 'react';
import { findLink, readString, type SirenSubEntity } from '../siren/index.ts';
import { paletteOf } from '../theme/index.ts';
import { Link } from './Link.tsx';
import { Monogram } from './Monogram.tsx';
import './StoreCard.css';

/**
 * The `store-card` component: a store on the home of the platform, in its own palette. The name
 * is the link, so a screen reader hears one short link per store; the whole card still takes
 * the click, and the call to enter it, the words of the BFF, shows for whoever looks.
 */
export function StoreCard({ entity }: { readonly entity: SirenSubEntity }): ReactElement | null {
  const name = readString(entity.properties, 'name');
  const tagline = readString(entity.properties, 'tagline');
  const initial = readString(entity.properties, 'initial') ?? name?.slice(0, 1);
  const palette = paletteOf(entity.properties?.palette);
  const self = findLink(entity.links, 'self');

  if (name === undefined || initial === undefined || self === undefined) {
    return null;
  }

  return (
    <li className="store-card card" data-palette={palette}>
      <div className="store-card__head">
        <Monogram initial={initial} palette={palette} size="medium" />
        <h2 className="store-card__name">
          <Link link={self} className="store-card__link">
            {name}
          </Link>
        </h2>
      </div>
      {tagline !== undefined ? <p className="store-card__tagline">{tagline}</p> : null}
      {self.title !== undefined ? (
        <span className="store-card__call" aria-hidden="true">
          {self.title}
        </span>
      ) : null}
    </li>
  );
}
