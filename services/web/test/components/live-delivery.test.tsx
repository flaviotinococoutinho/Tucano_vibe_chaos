import { screen as pageScreen, render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { LiveDelivery } from '../../src/components/index.ts';
import { fakeClock } from '../support/fakeClock.ts';
import { type FakeWebSocket, fakeSocketFactory } from '../support/fakeWebSocket.ts';

const HREF = '/api/tracking/v1/live?trackingCode=TX02Q6AGJQ45G00';

// The visible status paragraph and the hidden aria-live announcement often carry the same
// text at once (a fresh status is exactly what gets announced); reading the paragraph
// directly, as test/hypermedia/live.test.tsx does for the order screen, keeps this unambiguous.
function statusText(): string | null {
  return document.querySelector('.live-delivery__status')?.textContent ?? null;
}

function firstSocket(sockets: readonly FakeWebSocket[]): FakeWebSocket {
  const socket = sockets[0];
  if (socket === undefined) {
    throw new Error('expected LiveDelivery to have opened a socket');
  }
  return socket;
}

function position(overrides: Partial<Record<string, unknown>> = {}): Record<string, unknown> {
  return {
    type: 'position',
    trackingCode: 'TX02Q6AGJQ45G00',
    latitude: -19.9112,
    longitude: -44.0321,
    at: '2026-09-28T21:56:13.634Z',
    remainingMeters: 3120,
    ...overrides,
  };
}

describe('LiveDelivery', () => {
  it('shows "Conectando" until the first position arrives', () => {
    const { createSocket } = fakeSocketFactory();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={fakeClock()} />);

    expect(pageScreen.getByRole('heading', { name: 'Ao vivo' })).toBeInTheDocument();
    expect(pageScreen.getByText('Conectando')).toBeInTheDocument();
  });

  it('builds the socket URL from the href, resolved against this ws: page', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={fakeClock()} />);

    const url = new URL(firstSocket(sockets).url);
    expect(url.protocol).toBe('ws:');
    expect(url.host).toBe(window.location.host);
    expect(url.pathname).toBe('/api/tracking/v1/live');
    expect(url.searchParams.get('trackingCode')).toBe('TX02Q6AGJQ45G00');
  });

  it('shows the distance below 1000 m in meters', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={fakeClock()} />);

    firstSocket(sockets).open();
    firstSocket(sockets).message(position({ remainingMeters: 350 }));

    expect(statusText()).toBe('O entregador está a 350 m');
  });

  it('shows the distance at or above 1000 m in kilometers, one decimal, a comma', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={fakeClock()} />);

    firstSocket(sockets).message(position({ remainingMeters: 1234 }));

    expect(statusText()).toBe('O entregador está a 1,2 km');
  });

  it('draws the map once a position has arrived, hidden from assistive tech', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    const { container } = render(
      <LiveDelivery href={HREF} createSocket={createSocket} clock={fakeClock()} />,
    );

    expect(container.querySelector('svg.live-delivery__map')).not.toBeInTheDocument();
    firstSocket(sockets).message(position());

    const svg = container.querySelector('svg.live-delivery__map');
    expect(svg).toBeInTheDocument();
    expect(svg).toHaveAttribute('aria-hidden', 'true');
    expect(container.querySelector('circle.live-delivery__dot')).toBeInTheDocument();
  });

  it('ignores anything malformed and keeps waiting', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={fakeClock()} />);

    firstSocket(sockets).message('not json');
    firstSocket(sockets).message({ type: 'position', trackingCode: 'TX02Q6AGJQ45G00' });
    firstSocket(sockets).message({ type: 'teleported', trackingCode: 'TX02Q6AGJQ45G00' });

    expect(pageScreen.getByText('Conectando')).toBeInTheDocument();
  });

  it('shows "Entregue" for a delivered outcome and stops reconnecting', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    const clock = fakeClock();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={clock} />);

    firstSocket(sockets).message({
      type: 'ended',
      trackingCode: 'TX02Q6AGJQ45G00',
      outcome: 'delivered',
      at: '2026-09-28T21:56:33.101Z',
    });

    expect(statusText()).toBe('Entregue');
    expect(firstSocket(sockets).closed).toBe(true);

    clock.advance(60_000);
    expect(sockets).toHaveLength(1);
  });

  it('names a failed attempt in Portuguese and stops reconnecting', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    const clock = fakeClock();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={clock} />);

    firstSocket(sockets).message({
      type: 'ended',
      trackingCode: 'TX02Q6AGJQ45G00',
      outcome: 'delivery_failed',
      at: '2026-09-28T21:56:33.101Z',
    });

    expect(statusText()).toBe('O entregador não conseguiu entregar desta vez');

    clock.advance(60_000);
    expect(sockets).toHaveLength(1);
  });

  it('reconnects with a growing wait after a close, reset once a message gets through', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    const clock = fakeClock();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={clock} />);

    firstSocket(sockets).serverClose();
    expect(
      pageScreen.getByText(
        'A posição ao vivo não está disponível agora. O rastreio segue se atualizando sozinho.',
      ),
    ).toBeInTheDocument();

    clock.advance(999);
    expect(sockets).toHaveLength(1);
    clock.advance(1);
    expect(sockets).toHaveLength(2);

    const second = sockets[1];
    if (second === undefined) {
      throw new Error('expected a second socket after 1 s');
    }
    second.serverClose();
    clock.advance(1999);
    expect(sockets).toHaveLength(2);
    clock.advance(1);
    expect(sockets).toHaveLength(3);

    const third = sockets[2];
    if (third === undefined) {
      throw new Error('expected a third socket after 2 s');
    }
    third.open();
    third.message(position());
    third.serverClose();

    // A message got through on the third try, so the wait is back to 1 s, not 4 s.
    clock.advance(1000);
    expect(sockets).toHaveLength(4);
  });

  it('keeps retrying with the growing wait when the socket cannot even be created', () => {
    const clock = fakeClock();
    let attempts = 0;
    const createSocket = (): never => {
      attempts += 1;
      throw new Error('WebSocket is blocked in this context');
    };
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={clock} />);

    expect(
      pageScreen.getByText(
        'A posição ao vivo não está disponível agora. O rastreio segue se atualizando sozinho.',
      ),
    ).toBeInTheDocument();
    expect(attempts).toBe(1);

    clock.advance(1000);
    expect(attempts).toBe(2);
  });

  it('shows a stale notice once the last position is older than 30 s', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    const clock = fakeClock();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={clock} />);

    const at = new Date(0).toISOString();
    firstSocket(sockets).message(position({ remainingMeters: 1200, at }));
    expect(statusText()).toBe('O entregador está a 1,2 km');

    clock.advance(29_000);
    expect(pageScreen.queryByText(/Sem sinal/)).not.toBeInTheDocument();

    clock.advance(1000);
    expect(pageScreen.getByText('Sem sinal do entregador há 30 s')).toBeInTheDocument();
  });

  it('announces a 500 m band crossing, but not two positions in the same band', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={fakeClock()} />);

    firstSocket(sockets).message(position({ remainingMeters: 900 }));
    const region = pageScreen.getByRole('status');
    expect(region).toHaveTextContent('O entregador está a 900 m');

    // Same 500 m band (0): the announcement region does not change.
    firstSocket(sockets).message(position({ remainingMeters: 850 }));
    expect(region).toHaveTextContent('O entregador está a 900 m');

    // Crosses into the next band (500-999 -> 0-499): a fresh announcement.
    firstSocket(sockets).message(position({ remainingMeters: 400 }));
    expect(region).toHaveTextContent('O entregador está a 400 m');
  });

  it('announces going out of signal once, not on every tick that follows', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    const clock = fakeClock();
    render(<LiveDelivery href={HREF} createSocket={createSocket} clock={clock} />);

    firstSocket(sockets).message(position({ at: new Date(0).toISOString() }));
    clock.advance(30_000);

    const region = pageScreen.getByRole('status');
    expect(region).toHaveTextContent('Sem sinal do entregador');

    clock.advance(5000);
    expect(pageScreen.getByText('Sem sinal do entregador há 35 s')).toBeInTheDocument();
    expect(region).toHaveTextContent('Sem sinal do entregador');
  });

  it('closes the socket on unmount and does not reconnect afterwards', () => {
    const { createSocket, sockets } = fakeSocketFactory();
    const clock = fakeClock();
    const { unmount } = render(
      <LiveDelivery href={HREF} createSocket={createSocket} clock={clock} />,
    );

    expect(firstSocket(sockets).closed).toBe(false);
    unmount();
    expect(firstSocket(sockets).closed).toBe(true);

    clock.advance(60_000);
    expect(sockets).toHaveLength(1);
  });
});
