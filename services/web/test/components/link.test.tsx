import { fireEvent, screen as pageScreen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Link } from '../../src/components/index.ts';
import type { SirenLink } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways } from '../support/mockFetch.ts';
import { renderWithHypermedia } from '../support/renderWithHypermedia.tsx';

const PRODUCT_LINK: SirenLink = {
  rel: ['item'],
  href: '/bff/v1/products/BOOK-DDD-001',
  title: 'Domain-Driven Design',
};
const EXTERNAL_LINK: SirenLink = {
  rel: ['about'],
  class: ['external'],
  href: 'https://github.com/flaviotinococoutinho/chaos_playground',
  title: 'Sobre o projeto',
};

describe('Link', () => {
  it('renders a real <a href> to the mapped browser path', () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: fixtures.home }));
    renderWithHypermedia(<Link link={PRODUCT_LINK} />);

    const anchor = pageScreen.getByRole('link', { name: 'Domain-Driven Design' });
    expect(anchor).toHaveAttribute('href', '/products/BOOK-DDD-001');
  });

  it('follows a plain left click by fetching and pushing history, without a full navigation', async () => {
    mockFetchAlways(
      fakeResponse({
        url: 'http://localhost/bff/v1/products/BOOK-DDD-001',
        body: fixtures.product,
      }),
    );
    renderWithHypermedia(<Link link={PRODUCT_LINK} />);

    const user = userEvent.setup();
    await user.click(pageScreen.getByRole('link', { name: 'Domain-Driven Design' }));

    await waitFor(() => expect(window.location.pathname).toBe('/products/BOOK-DDD-001'));
    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      '/bff/v1/products/BOOK-DDD-001',
      expect.anything(),
    );
  });

  it('lets a modified click through, for the browser to open a new tab', () => {
    mockFetchAlways(
      fakeResponse({
        url: 'http://localhost/bff/v1/products/BOOK-DDD-001',
        body: fixtures.product,
      }),
    );
    renderWithHypermedia(<Link link={PRODUCT_LINK} />);
    const fetchMock = vi.mocked(fetch);
    const callsBeforeClick = fetchMock.mock.calls.length;

    const anchor = pageScreen.getByRole('link', { name: 'Domain-Driven Design' });
    const event = fireEvent.click(anchor, { ctrlKey: true, button: 0 });

    // fireEvent.click returns false when a listener called preventDefault; a Ctrl-click must not.
    expect(event).toBe(true);
    // No extra fetch: the click was left for the browser, not intercepted for client-side navigation.
    expect(fetchMock.mock.calls.length).toBe(callsBeforeClick);
  });

  it('renders an external link as a plain, new-tab anchor', () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: fixtures.home }));
    renderWithHypermedia(<Link link={EXTERNAL_LINK} />);

    const anchor = pageScreen.getByRole('link', { name: 'Sobre o projeto' });
    expect(anchor).toHaveAttribute('href', EXTERNAL_LINK.href);
    expect(anchor).toHaveAttribute('target', '_blank');
    expect(anchor).toHaveAttribute('rel', 'noopener');
  });
});
