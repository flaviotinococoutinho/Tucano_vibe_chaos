import { screen as pageScreen, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TrackingScreen } from '../../src/screens/index.ts';
import type { SirenScreen } from '../../src/siren/index.ts';
import { stubInertGlobalWebSocket } from '../support/fakeWebSocket.ts';
import { fixtures } from '../support/fixtures.ts';

// contracts/http/bff/README.md#rel-live, offered only while a parcel of the own fleet is out
// for delivery - the shipped example fixture predates it, so tests add it by hand here.
const REL_LIVE =
  'https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-live';

function withLiveLink(screen: SirenScreen, href: string): SirenScreen {
  return { ...screen, links: [...screen.links, { rel: [REL_LIVE], href }] };
}

// TrackingScreen falls back to LiveDelivery's own default factory (a real WebSocket) once a
// rel-live link is present; jsdom's WebSocket is a real one (undici-backed), so it is stubbed
// inert here too, exactly like the example-fixture tests.
beforeEach(() => {
  stubInertGlobalWebSocket();
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('the tracking screen', () => {
  it('renders no live card when the screen carries no rel-live link', () => {
    render(<TrackingScreen screen={fixtures.tracking} />);

    expect(pageScreen.queryByRole('heading', { name: 'Ao vivo' })).not.toBeInTheDocument();
  });

  it('renders the live card from the rel-live link, above the timeline', () => {
    const screen = withLiveLink(
      fixtures.tracking,
      '/api/tracking/v1/live?trackingCode=TX02PX83TXC5G00',
    );
    const { container } = render(<TrackingScreen screen={screen} />);

    expect(pageScreen.getByRole('heading', { name: 'Ao vivo' })).toBeInTheDocument();
    const liveIndex = container.innerHTML.indexOf('live-delivery');
    const timelineIndex = container.innerHTML.indexOf('tracking-screen__timeline');
    expect(liveIndex).toBeGreaterThan(-1);
    expect(timelineIndex).toBeGreaterThan(-1);
    expect(liveIndex).toBeLessThan(timelineIndex);
  });
});
