import { screen as pageScreen, render, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Header } from '../../src/components/index.ts';
import { HypermediaProvider, toBrowserPath } from '../../src/hypermedia/index.ts';
import { ScreenRouter } from '../../src/screens/index.ts';
import {
  findEntity,
  findLink,
  REL,
  readShopper,
  readStore,
  readString,
  type SirenScreen,
} from '../../src/siren/index.ts';
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

const NOT_FOUND = {
  type: 'about:blank',
  title: 'Not Found',
  status: 404,
  detail: 'Não encontrei.',
};

function renderApp(): void {
  render(
    <HypermediaProvider>
      <Header />
      <ScreenRouter />
    </HypermediaProvider>,
  );
}

/** The header over the screen the BFF answers at the address of its self link, as the app puts them together. */
async function renderScreen(screen: SirenScreen): Promise<void> {
  const self = findLink(screen.links, 'self')?.href ?? '/bff/v1';
  mockFetchAlways(fakeResponse({ url: `http://localhost${self}`, body: screen }));
  renderApp();
  await pageScreen.findByRole('heading', { level: 1, name: screen.title });
}

/** A link of the navigation of the example: its title, and where the browser goes. */
function navigationLink(screen: SirenScreen, rel: string): { name: string; path: string } {
  const link = findLink(findEntity(screen.entities, REL.navigation)?.links, rel);
  if (link?.title === undefined) {
    throw new Error(`the navigation of "${screen.title}" has no titled link ${rel}`);
  }
  return { name: link.title, path: toBrowserPath(link.href) };
}

function banner(): HTMLElement {
  return pageScreen.getByRole('banner');
}

function mainNavigation(): HTMLElement {
  return pageScreen.getByRole('navigation', { name: 'Principal' });
}

describe('the header', () => {
  it('draws the links of the screen, and the chip of the profile shopping', async () => {
    const { orders } = fixtures;
    await renderScreen(orders);

    const shopper = readShopper(findEntity(orders.entities, REL.navigation)?.properties);
    const catalog = navigationLink(orders, REL.catalog);
    const list = navigationLink(orders, REL.orders);
    expect(within(mainNavigation()).getByRole('link', { name: catalog.name })).not.toHaveAttribute(
      'aria-current',
    );
    expect(within(mainNavigation()).getByRole('link', { name: list.name })).toHaveAttribute(
      'aria-current',
      'page',
    );
    expect(
      within(mainNavigation()).getByRole('link', { name: `Perfil: ${shopper?.label}` }),
    ).toHaveAttribute('href', navigationLink(orders, REL.profiles).path);
  });

  it('tells an order is inside "Meus pedidos", without calling it the page itself', async () => {
    const { orderShipped } = fixtures;
    await renderScreen(orderShipped);

    expect(
      within(mainNavigation()).getByRole('link', {
        name: navigationLink(orderShipped, REL.orders).name,
      }),
    ).toHaveAttribute('aria-current', 'true');
  });

  it('shows the store as the brand inside a store, with a quiet way back to all the stores', async () => {
    const { store } = fixtures;
    await renderScreen(store);

    const brand = navigationLink(store, REL.store);
    const allStores = navigationLink(store, REL.stores);
    expect(within(banner()).getByRole('link', { name: brand.name })).toHaveAttribute(
      'href',
      brand.path,
    );
    expect(within(banner()).getByRole('link', { name: allStores.name })).toHaveAttribute(
      'href',
      allStores.path,
    );
    expect(within(banner()).queryByRole('link', { name: 'Tucano' })).not.toBeInTheDocument();
    for (const rel of [REL.catalog, REL.orders]) {
      const link = navigationLink(store, rel);
      expect(within(mainNavigation()).getByRole('link', { name: link.name })).toHaveAttribute(
        'href',
        link.path,
      );
    }
  });

  it('keeps the brand of Tucano on the screens of the platform, with only the profiles to go to', async () => {
    const { home } = fixtures;
    await renderScreen(home);

    expect(within(banner()).getByRole('link', { name: 'Tucano' })).toHaveAttribute(
      'href',
      navigationLink(home, REL.stores).path,
    );
    expect(
      within(banner()).queryByRole('link', { name: navigationLink(home, REL.stores).name }),
    ).not.toBeInTheDocument();
    expect(within(mainNavigation()).getAllByRole('link')).toHaveLength(1);
  });

  it('invites a browser with nobody shopping yet to come in', async () => {
    const { home } = fixtures;
    await renderScreen(home);

    const profiles = navigationLink(home, REL.profiles);
    expect(within(mainNavigation()).getByRole('link', { name: profiles.name })).toHaveAttribute(
      'href',
      profiles.path,
    );
    expect(within(mainNavigation()).queryByText(/^Perfil:/)).not.toBeInTheDocument();
  });

  it('keeps the links of the last screen over a problem, so the way to the profiles stays', async () => {
    const { orders } = fixtures;
    const orderHref = findLink(findEntity(orders.entities, 'item')?.links, 'self')?.href;
    if (orderHref === undefined) {
      throw new Error('the orders example has no order to open');
    }
    mockFetchSequence([
      fakeResponse({
        url: `http://localhost${findLink(orders.links, 'self')?.href}`,
        body: orders,
      }),
      fakeResponse({ status: 404, url: `http://localhost${orderHref}`, body: NOT_FOUND }),
    ]);
    renderApp();
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
    expect(
      within(mainNavigation()).getByRole('link', { name: `Perfil: ${shopper?.label}` }),
    ).toHaveAttribute('href', navigationLink(orders, REL.profiles).path);
    expect(
      within(banner()).getByRole('link', { name: navigationLink(orders, REL.store).name }),
    ).toBeInTheDocument();
  });

  it('keeps only the brand and the toggle on a problem at the very first screen', async () => {
    mockFetchAlways(
      fakeResponse({
        status: 404,
        url: 'http://localhost/bff/v1/stores/arara/orders/0199a2b4-0000-7000-8000-000000000000',
        body: NOT_FOUND,
      }),
    );
    renderApp();

    await pageScreen.findByRole('heading', { level: 1, name: 'Não encontrado' });
    expect(within(banner()).getByRole('link', { name: 'Tucano' })).toHaveAttribute('href', '/');
    expect(within(banner()).getByRole('button', { name: /Mudar para o tema/ })).toBeInTheDocument();
    expect(within(banner()).queryByRole('navigation')).not.toBeInTheDocument();
    expect(document.documentElement.dataset.palette).toBeUndefined();
  });
});

describe('the palette of the page', () => {
  it('is the palette of the store on show, and the look of the platform outside it', async () => {
    const { store, home } = fixtures;
    const palette = readStore(findEntity(store.entities, REL.navigation)?.properties)?.palette;
    mockFetchSequence([
      fakeResponse({ url: 'http://localhost/bff/v1/stores/arara', body: store }),
      fakeResponse({ url: 'http://localhost/bff/v1', body: home }),
    ]);
    renderApp();
    await pageScreen.findByRole('heading', { level: 1, name: store.title });

    expect(palette).toBe('arara');
    expect(document.documentElement.dataset.palette).toBe(palette);
    expect(banner()).toHaveClass('app-header--store');

    await userEvent.click(
      within(banner()).getByRole('link', { name: navigationLink(store, REL.stores).name }),
    );
    await pageScreen.findByRole('heading', { level: 1, name: home.title });

    await waitFor(() => expect(document.documentElement.dataset.palette).toBeUndefined());
    expect(banner()).not.toHaveClass('app-header--store');
  });

  it('stays the palette of the last store over a problem, like the links of the header', async () => {
    const { catalog } = fixtures;
    const product = findEntity(catalog.entities, 'item');
    const productHref = findLink(product?.links, 'self')?.href;
    const productName = readString(product?.properties, 'name');
    if (productHref === undefined || productName === undefined) {
      throw new Error('the catalog example has no product to open');
    }
    mockFetchSequence([
      fakeResponse({ url: 'http://localhost/bff/v1/stores/arara/products', body: catalog }),
      fakeResponse({ status: 404, url: `http://localhost${productHref}`, body: NOT_FOUND }),
    ]);
    renderApp();
    await pageScreen.findByRole('heading', { level: 1, name: catalog.title });

    await userEvent.click(
      within(pageScreen.getByRole('main')).getByRole('link', { name: new RegExp(productName) }),
    );
    await pageScreen.findByRole('heading', { level: 1, name: 'Não encontrado' });

    expect(document.documentElement.dataset.palette).toBe('arara');
    expect(banner()).toHaveClass('app-header--store');
  });

  it('never wears a palette the web does not know yet, and keeps the store as the brand', async () => {
    const { store } = fixtures;
    const unknown: SirenScreen = {
      ...store,
      entities: store.entities?.map((entity) =>
        entity.rel.includes(REL.navigation)
          ? {
              ...entity,
              properties: {
                ...entity.properties,
                store: { slug: 'arara', name: 'Arara Livros', palette: 'jandaia', initial: 'A' },
              },
            }
          : entity,
      ),
    };
    await renderScreen(unknown);

    expect(document.documentElement.dataset.palette).toBeUndefined();
    expect(within(banner()).getByRole('link', { name: 'Arara Livros' })).toBeInTheDocument();
  });
});
