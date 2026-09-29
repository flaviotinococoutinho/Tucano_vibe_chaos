import { screen as pageScreen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { toBrowserPath } from '../../src/hypermedia/index.ts';
import { OrdersScreen } from '../../src/screens/index.ts';
import { entitiesOf, findLink, REL, readProgress, type SirenLink } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways } from '../support/mockFetch.ts';
import { renderWithHypermedia } from '../support/renderWithHypermedia.tsx';

/** Where a link of the example takes the browser: the store is already in it, as the BFF sent it. */
function pathOf(link: SirenLink | undefined): string {
  if (link === undefined) {
    throw new Error('the example has no such link');
  }
  return toBrowserPath(link.href);
}

describe('the orders screen', () => {
  it('heads each order with the one link to it, never the whole card read out', () => {
    const { orders } = fixtures;
    mockFetchAlways(
      fakeResponse({ url: 'http://localhost/bff/v1/stores/arara/orders', body: orders }),
    );
    renderWithHypermedia(<OrdersScreen screen={orders} />);

    const summaries = entitiesOf(orders, 'item');
    const [list] = pageScreen.getAllByRole('list');
    if (list === undefined) {
      throw new Error('the orders screen shows no list');
    }
    expect(within(list).getAllByRole('link')).toHaveLength(summaries.length);
    for (const summary of summaries) {
      const heading = pageScreen.getByRole('heading', { level: 2, name: summary.title });
      expect(within(heading).getByRole('link', { name: summary.title })).toHaveAttribute(
        'href',
        pathOf(findLink(summary.links, 'self')),
      );
      expect(pageScreen.getByText(String(summary.properties?.itemsLabel))).toBeInTheDocument();
      expect(pageScreen.getByText(String(summary.properties?.statusLabel))).toBeInTheDocument();
    }
  });

  it('says where a stopped order stopped, next to its badge, and never says the badge twice', () => {
    const { orders } = fixtures;
    mockFetchAlways(
      fakeResponse({ url: 'http://localhost/bff/v1/stores/arara/orders', body: orders }),
    );
    renderWithHypermedia(<OrdersScreen screen={orders} />);

    for (const summary of entitiesOf(orders, 'item')) {
      const card = pageScreen.getByRole('heading', { level: 2, name: summary.title }).closest('li');
      if (card === null) {
        throw new Error(`the order "${summary.title}" is not a card of the list`);
      }
      const statusLabel = String(summary.properties?.statusLabel);
      const stopped = readProgress(summary.properties)?.find(({ state }) => state === 'stopped');
      expect(within(card).getAllByText(statusLabel)).toHaveLength(1);
      if (stopped !== undefined) {
        expect(within(card).getByText(stopped.label)).toBeInTheDocument();
      }
    }
  });

  it('offers the pages around the one on show', () => {
    const { orders } = fixtures;
    mockFetchAlways(
      fakeResponse({ url: 'http://localhost/bff/v1/stores/arara/orders', body: orders }),
    );
    renderWithHypermedia(<OrdersScreen screen={orders} />);

    const prev = findLink(orders.links, 'prev');
    const pages = pageScreen.getByRole('navigation', { name: 'Páginas de pedidos' });
    expect(within(pages).getByRole('link', { name: prev?.title })).toHaveAttribute(
      'href',
      pathOf(prev),
    );
  });

  it('greets a list with no orders with the mascot and the way to the catalog', () => {
    const { ordersEmpty } = fixtures;
    mockFetchAlways(
      fakeResponse({ url: 'http://localhost/bff/v1/stores/arara/orders', body: ordersEmpty }),
    );
    renderWithHypermedia(<OrdersScreen screen={ordersEmpty} />);

    const catalog = findLink(ordersEmpty.links, REL.catalog);
    expect(pageScreen.getByText('Nenhum pedido por aqui ainda.')).toBeInTheDocument();
    expect(pageScreen.getByRole('img')).toHaveAttribute('src', '/illustrations/ui-empty.webp');
    expect(pageScreen.getByRole('link', { name: catalog?.title })).toHaveAttribute(
      'href',
      pathOf(catalog),
    );
    expect(pageScreen.queryByRole('list')).not.toBeInTheDocument();
  });
});
