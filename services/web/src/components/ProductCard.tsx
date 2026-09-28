import type { ReactElement } from 'react';
import { findLink, readMoney, readString, type SirenSubEntity } from '../siren/index.ts';
import { Link } from './Link.tsx';
import { Money } from './Money.tsx';
import './ProductCard.css';

/** The `product-card` component: a catalog entry, linking to its own product screen. */
export function ProductCard({ entity }: { readonly entity: SirenSubEntity }): ReactElement | null {
  const name = readString(entity.properties, 'name');
  const categoryLabel = readString(entity.properties, 'categoryLabel');
  const price = readMoney(entity.properties, 'price');
  const self = findLink(entity.links, 'self');

  if (name === undefined || self === undefined) {
    return null;
  }

  return (
    <Link link={self} className="product-card card">
      {categoryLabel !== undefined ? (
        <span className="product-card__category">{categoryLabel}</span>
      ) : null}
      <span className="product-card__name">{name}</span>
      {price !== undefined ? (
        <span className="product-card__price">
          <Money value={price} />
        </span>
      ) : null}
    </Link>
  );
}
