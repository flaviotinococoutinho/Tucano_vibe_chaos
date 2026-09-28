import { screen as pageScreen, render, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { HypermediaProvider } from '../../src/hypermedia/index.ts';
import { ScreenRouter } from '../../src/screens/index.ts';
import { findLink, readString, type SirenScreen } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchSequence } from '../support/mockFetch.ts';

// Real timers throughout (fake timers and React's own effect scheduling fight each other);
// refreshAfterSeconds is overridden to a few milliseconds so the tests stay fast. Everything
// else - the tone, the labels, the links - is the real fixture.
function liveFixture(refreshAfterSeconds: number): SirenScreen {
  const base = fixtures.orderAwaitingPayment;
  return { ...base, properties: { ...base.properties, refreshAfterSeconds } };
}

function setHidden(hidden: boolean): void {
  Object.defineProperty(document, 'hidden', { value: hidden, configurable: true });
}

// The visible status badge and the hidden aria-live announcement can carry the same text at
// once; reading the badge's own paragraph directly keeps the assertion unambiguous.
function statusText(): string | null {
  return document.querySelector('.order-screen__status')?.textContent ?? null;
}

describe('a live screen', () => {
  afterEach(() => {
    setHidden(false);
  });

  it('refetches its self link after refreshAfterSeconds and renders what comes back', async () => {
    const first = liveFixture(0.05);
    const second = fixtures.orderShipped;
    const selfHref = findLink(first.links, 'self')?.href;
    const firstLabel = readString(first.properties, 'statusLabel');
    const secondLabel = readString(second.properties, 'statusLabel');
    if (selfHref === undefined || firstLabel === undefined || secondLabel === undefined) {
      throw new Error('fixture "order-awaiting-payment" is expected to be live');
    }

    mockFetchSequence([
      fakeResponse({ url: `http://localhost${selfHref}`, body: first }),
      fakeResponse({ url: `http://localhost${selfHref}`, body: second }),
    ]);

    render(
      <HypermediaProvider>
        <ScreenRouter />
      </HypermediaProvider>,
    );
    await pageScreen.findByText(firstLabel);

    await waitFor(() => expect(statusText()).toBe(secondLabel));

    const fetchMock = vi.mocked(fetch);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[1]?.[0]).toBe(selfHref);
  });

  it('pauses while the tab is hidden and resumes once it is visible again', async () => {
    const first = liveFixture(0.05);
    const second = fixtures.orderShipped;
    const firstLabel = readString(first.properties, 'statusLabel');
    const secondLabel = readString(second.properties, 'statusLabel');
    const selfHref = findLink(first.links, 'self')?.href;
    if (firstLabel === undefined || secondLabel === undefined || selfHref === undefined) {
      throw new Error('fixture "order-awaiting-payment" is expected to be live');
    }

    mockFetchSequence([fakeResponse({ url: `http://localhost${selfHref}`, body: first })]);
    const fetchMock = vi.mocked(fetch);

    render(
      <HypermediaProvider>
        <ScreenRouter />
      </HypermediaProvider>,
    );
    await pageScreen.findByText(firstLabel);

    setHidden(true);
    await new Promise((resolve) => setTimeout(resolve, 250));
    expect(fetchMock).toHaveBeenCalledTimes(1);

    mockFetchSequence([fakeResponse({ url: `http://localhost${selfHref}`, body: second })]);
    setHidden(false);
    document.dispatchEvent(new Event('visibilitychange'));

    await waitFor(() => expect(statusText()).toBe(secondLabel));
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });
});
