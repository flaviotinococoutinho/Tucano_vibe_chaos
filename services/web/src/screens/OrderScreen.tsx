import type { ReactElement } from 'react';
import { ActionForm, Badge, InstantText, Link, Money, OrderLine } from '../components/index.ts';
import {
  entitiesOf,
  findAction,
  readInstant,
  readMoney,
  readString,
  readTone,
  type SirenScreen,
} from '../siren/index.ts';
import './OrderScreen.css';

export function OrderScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const orderNumber = readString(screen.properties, 'orderNumber');
  const statusLabel = readString(screen.properties, 'statusLabel');
  const tone = readTone(screen.properties, 'tone');
  const placedAt = readInstant(screen.properties, 'placedAt');
  const reservationExpiresAt = readInstant(screen.properties, 'reservationExpiresAt');
  const total = readMoney(screen.properties, 'total');
  const trackingCode = readString(screen.properties, 'trackingCode');
  const lines = entitiesOf(screen, 'item');
  const pay = findAction(screen.actions, 'pay');
  const otherLinks = screen.links.filter((link) => !link.rel.includes('self'));

  return (
    <div className="order-screen">
      {statusLabel !== undefined && tone !== undefined ? (
        <p className="order-screen__status">
          <Badge tone={tone}>{statusLabel}</Badge>
        </p>
      ) : null}
      <dl className="order-screen__meta">
        {orderNumber !== undefined ? (
          <div>
            <dt>Pedido</dt>
            <dd className="numeric">{orderNumber}</dd>
          </div>
        ) : null}
        {placedAt !== undefined ? (
          <div>
            <dt>Feito em</dt>
            <dd>
              <InstantText value={placedAt} />
            </dd>
          </div>
        ) : null}
        {reservationExpiresAt !== undefined ? (
          <div>
            <dt>Reserva expira em</dt>
            <dd>
              <InstantText value={reservationExpiresAt} />
            </dd>
          </div>
        ) : null}
        {trackingCode !== undefined ? (
          <div>
            <dt>Rastreio</dt>
            <dd className="numeric">{trackingCode}</dd>
          </div>
        ) : null}
      </dl>
      {lines.length > 0 ? (
        <ul className="order-screen__lines">
          {lines.map((entity, index) => (
            <OrderLine entity={entity} key={readString(entity.properties, 'sku') ?? index} />
          ))}
        </ul>
      ) : null}
      {total !== undefined ? (
        <p className="order-screen__total">
          <span>Total</span>
          <strong>
            <Money value={total} />
          </strong>
        </p>
      ) : null}
      {pay !== undefined ? <ActionForm action={pay} /> : null}
      {otherLinks.length > 0 ? (
        <nav className="order-screen__links" aria-label="Links do pedido">
          <ul>
            {otherLinks.map((link) => (
              <li key={link.href}>
                <Link link={link} className="button button--secondary" />
              </li>
            ))}
          </ul>
        </nav>
      ) : null}
    </div>
  );
}
