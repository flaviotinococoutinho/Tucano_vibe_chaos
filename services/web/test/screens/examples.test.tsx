import { screen as pageScreen, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { HypermediaProvider } from '../../src/hypermedia/index.ts';
import { ScreenRouter } from '../../src/screens/index.ts';
import { screenClassOf } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { stubInertGlobalWebSocket } from '../support/fakeWebSocket.ts';
import { allScreenFixtures, fixtures } from '../support/fixtures.ts';
import { mockFetchAlways } from '../support/mockFetch.ts';

// A tracking example can carry a rel-live link (contracts/http/bff/README.md#rel-live); its
// screen then opens a WebSocket with LiveDelivery's own default factory. A real WebSocket in
// jsdom is a real socket (jsdom 30 backs it with undici), so every test here gets an inert
// stub instead - these tests only care that the screen's own heading renders.
beforeEach(() => {
  stubInertGlobalWebSocket();
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('every example screen renders', () => {
  it.each(
    allScreenFixtures.map(
      (fixture, index) =>
        [`${index + 1}. ${screenClassOf(fixture)}: ${fixture.title}`, fixture] as const,
    ),
  )('%s', async (_name, fixture) => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: fixture }));

    render(
      <HypermediaProvider>
        <ScreenRouter />
      </HypermediaProvider>,
    );

    expect(
      await pageScreen.findByRole('heading', { level: 1, name: fixture.title }),
    ).toBeInTheDocument();
  });
});

describe('a screen with a notice', () => {
  it('shows the notice, so a cancelled order says why before anything else', async () => {
    const { orderCancelled } = fixtures;
    const notice = orderCancelled.properties?.notice as { readonly text: string };
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: orderCancelled }));

    render(
      <HypermediaProvider>
        <ScreenRouter />
      </HypermediaProvider>,
    );

    expect(await pageScreen.findByText(notice.text)).toBeInTheDocument();
    expect(pageScreen.queryByRole('button', { name: 'Pagar' })).not.toBeInTheDocument();
  });
});
