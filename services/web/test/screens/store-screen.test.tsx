import { screen as pageScreen, render, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { HypermediaProvider, toBrowserPath } from '../../src/hypermedia/index.ts';
import { ScreenRouter, StoreScreen } from '../../src/screens/index.ts';
import { findAction, findLink, REL, readString } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways, mockFetchSequence } from '../support/mockFetch.ts';
import { renderWithHypermedia } from '../support/renderWithHypermedia.tsx';

const { store } = fixtures;

describe('the home of a store', () => {
  it('shows the sign of the store in its palette, and its tagline', () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1/stores/arara', body: store }));
    renderWithHypermedia(<StoreScreen screen={store} />);

    const tagline = pageScreen.getByText(readString(store.properties, 'tagline') ?? '');
    const sign = tagline.closest('section');
    expect(sign).toHaveAttribute('data-palette', readString(store.properties, 'palette'));
    expect(
      within(sign ?? document.body).getByText(readString(store.properties, 'initial') ?? ''),
    ).toHaveAttribute('aria-hidden', 'true');
  });

  it('leads into the catalog of the store', () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1/stores/arara', body: store }));
    renderWithHypermedia(<StoreScreen screen={store} />);

    const catalog = findLink(store.links, REL.catalog);
    expect(pageScreen.getByRole('link', { name: catalog?.title })).toHaveAttribute(
      'href',
      toBrowserPath(catalog?.href ?? ''),
    );
  });

  it('tracks a code inside the store, where the form of the store sends it', async () => {
    const track = findAction(store.actions, 'track-by-code');
    mockFetchSequence([
      fakeResponse({ url: 'http://localhost/bff/v1/stores/arara', body: store }),
      fakeResponse({
        url: 'http://localhost/bff/v1/stores/arara/tracking/TX02PX83TXC5G00',
        body: fixtures.tracking,
      }),
    ]);
    render(
      <HypermediaProvider>
        <ScreenRouter />
      </HypermediaProvider>,
    );
    await pageScreen.findByRole('heading', { level: 1, name: store.title });
    const section = pageScreen.getByRole('region', { name: track?.title });

    const user = userEvent.setup();
    await user.type(within(section).getByRole('textbox'), 'tx02px83txc5g00');
    await user.click(within(section).getByRole('button', { name: track?.title ?? '' }));

    await pageScreen.findByRole('heading', { level: 1, name: fixtures.tracking.title });
    const [asked] = vi.mocked(fetch).mock.calls[1] ?? [];
    expect(String(asked)).toBe(`${track?.href}?code=tx02px83txc5g00`);
    await waitFor(() =>
      expect(window.location.pathname).toBe('/stores/arara/tracking/TX02PX83TXC5G00'),
    );
  });
});
