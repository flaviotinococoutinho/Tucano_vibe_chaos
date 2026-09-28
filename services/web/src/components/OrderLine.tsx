import type { ReactElement } from 'react';
import { readMoney, readNumber, readString, type SirenSubEntity } from '../siren/index.ts';
import { Money } from './Money.tsx';
import './OrderLine.css';

/** The `order-line` component: one item of an order, shown inside the order screen. */
export function OrderLine({ entity }: { readonly entity: SirenSubEntity }): ReactElement | null {
  const name = readString(entity.properties, 'name');
  const quantity = readNumber(entity.properties, 'quantity');
  const subtotal = readMoney(entity.properties, 'subtotal');

  if (name === undefined) {
    return null;
  }

  return (
    <li className="order-line">
      <span className="order-line__name">{name}</span>
      {quantity !== undefined ? (
        <span className="order-line__quantity numeric">{quantity}x</span>
      ) : null}
      {subtotal !== undefined ? (
        <span className="order-line__subtotal">
          <Money value={subtotal} />
        </span>
      ) : null}
    </li>
  );
}
