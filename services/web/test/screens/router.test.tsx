import { screen as pageScreen, render, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { HypermediaProvider } from '../../src/hypermedia/index.ts';
import { ScreenRouter } from '../../src/screens/index.ts';
import type { SirenLink } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways, mockFetchSequence } from '../support/mockFetch.ts';

function renderRouter(): void {
  render(
    <HypermediaProvider>
      <ScreenRouter />
    </HypermediaProvider>,
  );
}

function linkTitled(rel: string, links: readonly SirenLink[]): string {
  const link = links.find((candidate) => candidate.rel.includes(rel));
  if (link?.title === undefined) {
    throw new Error(`the example has no titled ${rel} link`);
  }
  return link.title;
}

describe('the screen router', () => {
  it('offers the way back a screen names, the collection before the start', async () => {
    const { product } = fixtures;
    mockFetchAlways(
      fakeResponse({ url: 'http://localhost/bff/v1/products/BOOK-DDD-001', body: product }),
    );
    renderRouter();

    const back = await pageScreen.findByRole('navigation', { name: 'Voltar' });
    expect(back).toHaveTextContent(linkTitled('collection', product.links));
    expect(back).not.toHaveTextContent(linkTitled('up', product.links));
  });

  it('offers no way back on the start, which has nowhere to go back to', async () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: fixtures.home }));
    renderRouter();

    await pageScreen.findByRole('heading', { level: 1, name: fixtures.home.title });
    expect(pageScreen.queryByRole('navigation', { name: 'Voltar' })).not.toBeInTheDocument();
  });

  it('leaves the focus alone on the first screen and moves it to the title after a navigation', async () => {
    const { home, catalog } = fixtures;
    mockFetchSequence([
      fakeResponse({ url: 'http://localhost/bff/v1', body: home }),
      fakeResponse({ url: 'http://localhost/bff/v1/products?page=1', body: catalog }),
    ]);
    renderRouter();

    const first = await pageScreen.findByRole('heading', { level: 1, name: home.title });
    expect(first).not.toHaveFocus();

    await userEvent.setup().click(
      pageScreen.getByRole('link', {
        name: linkTitled(
          'https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-catalog',
          home.links,
        ),
      }),
    );

    const second = await pageScreen.findByRole('heading', { level: 1, name: catalog.title });
    await waitFor(() => expect(second).toHaveFocus());
  });

  it('leaves validation to the server, which answers every field at once', async () => {
    mockFetchAlways(
      fakeResponse({ url: 'http://localhost/bff/v1/checkout', body: fixtures.checkout }),
    );
    renderRouter();

    await pageScreen.findByRole('heading', { level: 1, name: fixtures.checkout.title });
    const button = pageScreen.getByRole('button', { name: 'Fazer pedido' });
    expect(button.closest('form')).toHaveAttribute('novalidate');
  });
});
