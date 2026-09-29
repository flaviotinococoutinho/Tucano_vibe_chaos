import type { ReactElement } from 'react';
import {
  ActionForm,
  Badge,
  InstantText,
  Link,
  LiveDelivery,
  Money,
  OrderLine,
  OrderProgress,
  TimelineStep,
} from '../components/index.ts';
import {
  entitiesOf,
  findAction,
  findLink,
  REL,
  readInstant,
  readMoney,
  readProgress,
  readString,
  readTone,
  type SirenScreen,
} from '../siren/index.ts';
import './OrderScreen.css';

/**
 * The order and its story: where it is now (a status card with the sentence of the store),
 * the milestones, the courier live when there is one, the payment while it waits, the
 * history, then what was bought. The way back ("Meus pedidos") is the `collection` link,
 * which the router already draws above the title.
 */
export function OrderScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const statusLabel = readString(screen.properties, 'statusLabel');
  const tone = readTone(screen.properties, 'tone');
  const headline = readString(screen.properties, 'headline');
  const placedAt = readInstant(screen.properties, 'placedAt');
  const reservationExpiresAt = readInstant(screen.properties, 'reservationExpiresAt');
  const total = readMoney(screen.properties, 'total');
  const trackingCode = readString(screen.properties, 'trackingCode');
  const progress = readProgress(screen.properties) ?? [];
  const lines = entitiesOf(screen, 'item');
  const history = entitiesOf(screen, REL.history);
  const pay = findAction(screen.actions, 'pay');
  const live = findLink(screen.links, REL.live);
  const otherLinks = screen.links.filter(
    (link) =>
      !link.rel.includes('self') &&
      !link.rel.includes('collection') &&
      !link.rel.includes(REL.live),
  );

  return (
    <div className="order-screen">
      <section className="order-status card" aria-label="Situação do pedido">
        {statusLabel !== undefined && tone !== undefined ? (
          <p className="order-screen__status">
            <Badge tone={tone}>{statusLabel}</Badge>
          </p>
        ) : null}
        {headline !== undefined ? <p className="order-status__headline">{headline}</p> : null}
        <dl className="order-status__meta">
          {placedAt !== undefined ? (
            <div>
              <dt>Feito em</dt>
              <dd>
                <InstantText value={placedAt} />
              </dd>
            </div>
          ) : null}
          {total !== undefined ? (
            <div>
              <dt>Total</dt>
              <dd>
                <Money value={total} />
              </dd>
            </div>
          ) : null}
          {reservationExpiresAt !== undefined ? (
            <div>
              <dt>Pague até</dt>
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
      </section>

      {progress.length > 0 ? <OrderProgress progress={progress} /> : null}

      {live !== undefined ? <LiveDelivery href={live.href} /> : null}

      {pay !== undefined ? (
        <section className="order-screen__section" aria-labelledby="order-pay-heading">
          <h2 id="order-pay-heading">Pagamento</h2>
          <ActionForm action={pay} />
        </section>
      ) : null}

      {history.length > 0 ? (
        <section className="order-screen__section" aria-labelledby="order-history-heading">
          <h2 id="order-history-heading">Histórico</h2>
          <ol className="order-screen__timeline">
            {history.map((entity, index) => (
              <TimelineStep
                entity={entity}
                isCurrent={index === history.length - 1}
                // biome-ignore lint/suspicious/noArrayIndexKey: steps are an ordered history, not identified entities.
                key={index}
              />
            ))}
          </ol>
        </section>
      ) : null}

      {lines.length > 0 ? (
        <section className="order-screen__section" aria-labelledby="order-items-heading">
          <h2 id="order-items-heading">Itens</h2>
          <ul className="order-screen__lines">
            {lines.map((entity, index) => (
              <OrderLine entity={entity} key={readString(entity.properties, 'sku') ?? index} />
            ))}
          </ul>
          {total !== undefined ? (
            <p className="order-screen__total">
              <span>Total</span>
              <strong>
                <Money value={total} />
              </strong>
            </p>
          ) : null}
        </section>
      ) : null}

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
