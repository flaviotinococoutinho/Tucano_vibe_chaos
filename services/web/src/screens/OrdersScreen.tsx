import type { ReactElement } from 'react';
import { EmptyState, Link, OrderCard } from '../components/index.ts';
import {
  entitiesOf,
  findLink,
  REL,
  readNumber,
  readString,
  type SirenLink,
  type SirenScreen,
} from '../siren/index.ts';
import './OrdersScreen.css';

/** The orders of the shopper, newest first, a page at a time. */
export function OrdersScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const orders = entitiesOf(screen, 'item');
  const total = readNumber(screen.properties, 'total');
  const catalog = findLink(screen.links, REL.catalog);
  const pagination = (
    <Pagination prev={findLink(screen.links, 'prev')} next={findLink(screen.links, 'next')} />
  );

  if (orders.length === 0) {
    return (
      <div className="orders-screen">
        <EmptyState title="Nenhum pedido por aqui ainda.">
          {catalog !== undefined ? (
            <Link link={catalog} className="button button--primary">
              {catalog.title ?? catalog.href}
            </Link>
          ) : null}
        </EmptyState>
        {pagination}
      </div>
    );
  }

  return (
    <div className="orders-screen">
      {total !== undefined ? (
        <p className="orders-screen__total">{total === 1 ? '1 pedido' : `${total} pedidos`}</p>
      ) : null}
      <ul className="orders-screen__list">
        {orders.map((entity, index) => (
          <OrderCard
            entity={entity}
            key={
              readString(entity.properties, 'orderId') ??
              findLink(entity.links, 'self')?.href ??
              index
            }
          />
        ))}
      </ul>
      {pagination}
    </div>
  );
}

function Pagination({
  prev,
  next,
}: {
  readonly prev: SirenLink | undefined;
  readonly next: SirenLink | undefined;
}): ReactElement | null {
  if (prev === undefined && next === undefined) {
    return null;
  }

  return (
    <nav className="orders-screen__pagination" aria-label="Páginas de pedidos">
      {prev !== undefined ? (
        <Link link={prev} className="button button--secondary">
          {prev.title ?? prev.href}
        </Link>
      ) : (
        <span />
      )}
      {next !== undefined ? (
        <Link link={next} className="button button--secondary">
          {next.title ?? next.href}
        </Link>
      ) : null}
    </nav>
  );
}
