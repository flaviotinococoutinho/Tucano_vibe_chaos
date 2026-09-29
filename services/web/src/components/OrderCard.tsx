import type { ReactElement } from 'react';
import {
  findLink,
  readInstant,
  readMoney,
  readProgress,
  readString,
  readTone,
  type SirenSubEntity,
} from '../siren/index.ts';
import { Badge } from './Badge.tsx';
import { InstantText } from './InstantText.tsx';
import { Link } from './Link.tsx';
import { Money } from './Money.tsx';
import './OrderCard.css';
import { ProgressDots } from './ProgressDots.tsx';

/**
 * The `order-summary` component: an order in the list. The heading is the link, so a screen
 * reader hears one short link per order instead of a card read out whole; the rest of the
 * card still takes the click, for whoever points at it.
 */
export function OrderCard({ entity }: { readonly entity: SirenSubEntity }): ReactElement | null {
  const self = findLink(entity.links, 'self');
  const title = entity.title ?? readString(entity.properties, 'orderNumber');
  const statusLabel = readString(entity.properties, 'statusLabel');
  const tone = readTone(entity.properties, 'tone');
  const placedAt = readInstant(entity.properties, 'placedAt');
  const total = readMoney(entity.properties, 'total');
  const itemsLabel = readString(entity.properties, 'itemsLabel');
  const progress = readProgress(entity.properties) ?? [];
  // The milestone the order is at usually reads like the badge; where it says more (why a
  // cancelled order stopped), it shows next to it, and never twice.
  const milestone =
    progress.find(({ state }) => state === 'current' || state === 'stopped') ?? progress.at(-1);
  const where = milestone?.label !== statusLabel ? milestone?.label : undefined;

  if (self === undefined || title === undefined) {
    return null;
  }

  return (
    <li className="order-card card">
      <div className="order-card__top">
        <h2 className="order-card__title">
          <Link link={self} className="order-card__link">
            {title}
          </Link>
        </h2>
        {total !== undefined ? (
          <strong className="order-card__total">
            <Money value={total} />
          </strong>
        ) : null}
      </div>
      {itemsLabel !== undefined ? <p className="order-card__items">{itemsLabel}</p> : null}
      {placedAt !== undefined ? (
        <p className="order-card__placed">
          Feito em <InstantText value={placedAt} />
        </p>
      ) : null}
      <div className="order-card__status">
        <ProgressDots progress={progress} />
        {statusLabel !== undefined && tone !== undefined ? (
          <Badge tone={tone}>{statusLabel}</Badge>
        ) : null}
        {where !== undefined ? <span className="order-card__where">{where}</span> : null}
      </div>
    </li>
  );
}
