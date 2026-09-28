import type { ReactElement } from 'react';
import { ActionForm, Money } from '../components/index.ts';
import { findAction, readMoney, readNumber, readString, type SirenScreen } from '../siren/index.ts';
import './CheckoutScreen.css';

export function CheckoutScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const name = readString(screen.properties, 'name');
  const quantity = readNumber(screen.properties, 'quantity');
  const subtotal = readMoney(screen.properties, 'subtotal');
  const placeOrder = findAction(screen.actions, 'place-order');

  return (
    <div className="checkout-screen">
      {name !== undefined ? (
        <p className="checkout-screen__summary">
          <span>
            {quantity !== undefined ? <span className="numeric">{quantity}x </span> : null}
            {name}
          </span>
          {subtotal !== undefined ? (
            <strong className="checkout-screen__subtotal">
              <Money value={subtotal} />
            </strong>
          ) : null}
        </p>
      ) : null}
      {placeOrder !== undefined ? <ActionForm action={placeOrder} /> : null}
    </div>
  );
}
