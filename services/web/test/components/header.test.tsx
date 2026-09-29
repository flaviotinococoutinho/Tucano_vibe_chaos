import { screen as pageScreen, render, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Header } from '../../src/components/index.ts';
import { HypermediaProvider } from '../../src/hypermedia/index.ts';
import { ScreenRouter } from '../../src/screens/index.ts';
import { findEntity, findLink, REL, readShopper, type SirenScreen } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { stubInertGlobalWebSocket } from '../support/fakeWebSocket.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways, mockFetchSequence } from '../support/mockFetch.ts';

// The theme toggle asks the system for its color scheme, which jsdom does not implement: a
// light system is enough here. No screen of these tests opens the live card's WebSocket, but
// an inert one keeps it that way if an example ever does.
beforeEach(() => {
  stubInertGlobalWebSocket();
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: false,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  }));
});

afterEach(() => {
  vi.unstubAllGlobals();
});

/** The header over the screen the BFF answers at that address, as the app puts them together. */
async function renderAt(path: string, screen: SirenScreen): Promise<void> {
  mockFetchAlways(fakeResponse({ url: `http://localhost/bff/v1${path}`, body: screen }));
  render(
    <HypermediaProvider>
      <Header />
      <ScreenRouter />
    </HypermediaProvider>,
  );
  await pageScreen.findByRole('heading', { level: 1, name: screen.title });
}

function navigationLink(screen: SirenScreen, rel: string): string {
  const title = findLink(findEntity(screen.entities, REL.navigation)?.links, rel)?.title;
  if (title === undefined) {
    throw new Error(`the navigation of "${screen.title}" has no titled link ${rel}`);
  }
  return title;
}

describe('the header', () => {
  it('draws the links of the screen, and the chip of the profile shopping', async () => {
    const { orders } = fixtures;
    await renderAt('/orders?page=2', orders);

    const navigation = pageScreen.getByRole('navigation', { name: 'Principal' });
    const shopper = readShopper(findEntity(orders.entities, REL.navigation)?.properties);
    expect(
      within(navigation).getByRole('link', { name: navigationLink(orders, REL.catalog) }),
    ).not.toHaveAttribute('aria-current');
    expect(
      within(navigation).getByRole('link', { name: navigationLink(orders, REL.orders) }),
    ).toHaveAttribute('aria-current', 'page');
    expect(
      within(navigation).getByRole('link', { name: `Perfil: ${shopper?.label}` }),
    ).toHaveAttribute('href', '/profiles');
  });

  it('tells an order is inside "Meus pedidos", without calling it the page itself', async () => {
    const { orderShipped } = fixtures;
    const orderId = String(orderShipped.properties?.orderId);
    await renderAt(`/orders/${orderId}`, orderShipped);

    const navigation = pageScreen.getByRole('navigation', { name: 'Principal' });
    expect(
      within(navigation).getByRole('link', { name: navigationLink(orderShipped, REL.orders) }),
    ).toHaveAttribute('aria-current', 'true');
  });

  it('invites a browser with nobody shopping yet to come in', async () => {
    const { home } = fixtures;
    await renderAt('', home);

    const navigation = pageScreen.getByRole('navigation', { name: 'Principal' });
    expect(
      within(navigation).getByRole('link', { name: navigationLink(home, REL.profiles) }),
    ).toHaveAttribute('href', '/profiles');
    expect(within(navigation).queryByText(/^Perfil:/)).not.toBeInTheDocument();
  });

  it('keeps the links of the last screen over a problem, so the way to the profiles stays', async () => {
    const { orders } = fixtures;
    const firstOrder = findEntity(orders.entities, 'item');
    const orderHref = findLink(firstOrder?.links, 'self')?.href;
    if (orderHref === undefined) {
      throw new Error('the orders example has no order to open');
    }
    mockFetchSequence([
      fakeResponse({ url: 'http://localhost/bff/v1/orders', body: orders }),
      fakeResponse({
        status: 404,
        url: `http://localhost${orderHref}`,
        body: { type: 'about:blank', title: 'Not Found', status: 404, detail: 'Não encontrei.' },
      }),
    ]);
    render(
      <HypermediaProvider>
        <Header />
        <ScreenRouter />
      </HypermediaProvider>,
    );
    await pageScreen.findByRole('heading', { level: 1, name: orders.title });
    const shopper = readShopper(findEntity(orders.entities, REL.navigation)?.properties);

    const [firstCard] = within(pageScreen.getByRole('main')).getAllByRole('link', {
      name: /^Pedido /,
    });
    if (firstCard === undefined) {
      throw new Error('the orders screen shows no order to open');
    }
    await userEvent.click(firstCard);

    await pageScreen.findByRole('heading', { level: 1, name: 'Não encontrado' });
    const navigation = pageScreen.getByRole('navigation', { name: 'Principal' });
    expect(
      within(navigation).getByRole('link', { name: `Perfil: ${shopper?.label}` }),
    ).toHaveAttribute('href', '/profiles');
  });

  it('keeps only the brand and the toggle on a problem at the very first screen', async () => {
    mockFetchAlways(
      fakeResponse({
        status: 404,
        url: 'http://localhost/bff/v1/orders/0199a2b4-0000-7000-8000-000000000000',
        body: { type: 'about:blank', title: 'Not Found', status: 404, detail: 'Não encontrei.' },
      }),
    );
    render(
      <HypermediaProvider>
        <Header />
        <ScreenRouter />
      </HypermediaProvider>,
    );

    await pageScreen.findByRole('heading', { level: 1, name: 'Não encontrado' });
    const banner = pageScreen.getByRole('banner');
    expect(within(banner).getByRole('link', { name: 'Tucano' })).toBeInTheDocument();
    expect(within(banner).getByRole('button', { name: /Mudar para o tema/ })).toBeInTheDocument();
    expect(within(banner).queryByRole('navigation')).not.toBeInTheDocument();
  });
});
