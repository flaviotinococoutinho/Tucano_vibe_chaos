import { screen as pageScreen, render, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { HypermediaProvider } from '../../src/hypermedia/index.ts';
import { ScreenRouter } from '../../src/screens/index.ts';
import {
  entitiesOf,
  findLink,
  REL,
  readNotice,
  readString,
  type SirenScreen,
} from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { stubInertGlobalWebSocket } from '../support/fakeWebSocket.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways } from '../support/mockFetch.ts';

// The order of the own fleet carries a rel-live link, and its card opens a WebSocket.
beforeEach(() => {
  stubInertGlobalWebSocket();
});

afterEach(() => {
  vi.unstubAllGlobals();
});

async function renderOrder(screen: SirenScreen): Promise<void> {
  const self = findLink(screen.links, 'self')?.href ?? '/bff/v1';
  mockFetchAlways(fakeResponse({ url: `http://localhost${self}`, body: screen }));
  render(
    <HypermediaProvider>
      <ScreenRouter />
    </HypermediaProvider>,
  );
  await pageScreen.findByRole('heading', { level: 1, name: screen.title });
}

describe('the order screen', () => {
  it('says where the order is, in the words of the store, with the way back to the list', async () => {
    const { orderShipped } = fixtures;
    await renderOrder(orderShipped);

    const status = pageScreen.getByRole('region', { name: 'Situação do pedido' });
    expect(within(status).getByText(String(orderShipped.properties?.headline))).toBeInTheDocument();
    expect(
      within(status).getByText(String(orderShipped.properties?.trackingCode)),
    ).toBeInTheDocument();
    const back = pageScreen.getByRole('navigation', { name: 'Voltar' });
    expect(within(back).getByRole('link')).toHaveTextContent(
      String(findLink(orderShipped.links, 'collection')?.title),
    );
  });

  it('tells the story in its history, oldest first, the parcel included', async () => {
    const { orderShipped } = fixtures;
    await renderOrder(orderShipped);

    const history = pageScreen.getByRole('region', { name: 'Histórico' });
    const labels = entitiesOf(orderShipped, REL.history).map((step) =>
      readString(step.properties, 'label'),
    );
    const items = within(history).getAllByRole('listitem');
    expect(items).toHaveLength(labels.length);
    items.forEach((item, index) => {
      expect(item).toHaveTextContent(labels[index] ?? '');
    });
  });

  it('marks the last step of the history as the current one while the order still moves', async () => {
    await renderOrder(fixtures.orderShipped);

    const steps = within(pageScreen.getByRole('region', { name: 'Histórico' })).getAllByRole(
      'listitem',
    );
    expect(steps.at(-1)).toHaveClass('timeline-step--current');
  });

  it('marks no step as current once the order has arrived', async () => {
    await renderOrder(fixtures.orderDelivered);

    const steps = within(pageScreen.getByRole('region', { name: 'Histórico' })).getAllByRole(
      'listitem',
    );
    for (const step of steps) {
      expect(step).not.toHaveClass('timeline-step--current');
    }
  });

  it('shows why a step happened, when it has a reason', async () => {
    const { orderCancelled } = fixtures;
    await renderOrder(orderCancelled);

    const detail = entitiesOf(orderCancelled, REL.history)
      .map((step) => readString(step.properties, 'detail'))
      .find((text) => text !== undefined);
    expect(detail).toBeDefined();
    expect(pageScreen.getByText(String(detail))).toBeInTheDocument();
  });

  it('follows the courier live on the order while the own fleet is on the way', async () => {
    await renderOrder(fixtures.orderOutForDelivery);

    expect(pageScreen.getByRole('heading', { level: 2, name: 'Ao vivo' })).toBeInTheDocument();
    expect(
      pageScreen.queryByRole('link', {
        name: String(findLink(fixtures.orderOutForDelivery.links, REL.live)?.title),
      }),
    ).not.toBeInTheDocument();
  });

  it('opens without the delivery news, and says so, when logistics does not answer', async () => {
    const { orderWithoutDeliveryNews } = fixtures;
    await renderOrder(orderWithoutDeliveryNews);

    const notice = readNotice(orderWithoutDeliveryNews.properties);
    expect(pageScreen.getByText(String(notice?.text))).toBeInTheDocument();
    expect(pageScreen.getByRole('region', { name: 'Histórico' })).toBeInTheDocument();
  });

  it('offers the payment only while the order waits for it', async () => {
    await renderOrder(fixtures.orderPendingPayment);

    const payment = pageScreen.getByRole('region', { name: 'Pagamento' });
    expect(within(payment).getByRole('button', { name: 'Pagar' })).toBeInTheDocument();
  });
});
