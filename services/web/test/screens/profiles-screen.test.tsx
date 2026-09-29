import { screen as pageScreen, render, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { HypermediaProvider } from '../../src/hypermedia/index.ts';
import { ProfilesScreen, ScreenRouter } from '../../src/screens/index.ts';
import {
  entitiesOf,
  findAction,
  readBoolean,
  readString,
  type SirenAction,
} from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways, mockFetchSequence } from '../support/mockFetch.ts';
import { renderWithHypermedia } from '../support/renderWithHypermedia.tsx';

const { profiles } = fixtures;
const cards = entitiesOf(profiles, 'item');

function switchTo(label: string): SirenAction {
  const card = cards.find((profile) => readString(profile.properties, 'label') === label);
  const action = findAction(card?.actions, 'use-profile');
  if (action === undefined) {
    throw new Error(`the profile "${label}" offers no use-profile action`);
  }
  return action;
}

describe('the profiles screen', () => {
  it('marks the profile shopping, and offers each of the others its switch', () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1/profiles', body: profiles }));
    renderWithHypermedia(<ProfilesScreen screen={profiles} />);

    expect(pageScreen.getByText(String(profiles.properties?.intro))).toBeInTheDocument();
    for (const card of cards) {
      const label = readString(card.properties, 'label') ?? '';
      const item = pageScreen.getByRole('heading', { level: 2, name: label }).closest('li');
      if (item === null) {
        throw new Error(`the profile "${label}" is not a card of the list`);
      }
      if (readBoolean(card.properties, 'active')) {
        expect(within(item).getByText('Perfil atual')).toBeInTheDocument();
        expect(within(item).queryByRole('button')).not.toBeInTheDocument();
      } else {
        expect(within(item).queryByText('Perfil atual')).not.toBeInTheDocument();
        expect(
          within(item).getByRole('button', { name: switchTo(label).title }),
        ).toBeInTheDocument();
      }
    }
  });

  it('offers the form that creates a profile, with its name field', () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1/profiles', body: profiles }));
    renderWithHypermedia(<ProfilesScreen screen={profiles} />);

    const create = findAction(profiles.actions, 'create-profile');
    const section = pageScreen.getByRole('region', { name: 'Novo perfil' });
    expect(within(section).getByLabelText(/^Nome/)).toHaveAttribute('autocomplete', 'given-name');
    expect(within(section).getByRole('button', { name: create?.title })).toBeInTheDocument();
  });

  it('switches the profile and lands on the orders of the one chosen', async () => {
    const bruno = switchTo('Bruno');
    // fetch follows the 303 of the BFF by itself; what arrives is the orders screen.
    mockFetchSequence([
      fakeResponse({ url: 'http://localhost/bff/v1/profiles', body: profiles }),
      fakeResponse({ url: 'http://localhost/bff/v1/orders', body: fixtures.orders }),
    ]);
    render(
      <HypermediaProvider>
        <ScreenRouter />
      </HypermediaProvider>,
    );
    await pageScreen.findByRole('heading', { level: 1, name: profiles.title });

    await userEvent.setup().click(pageScreen.getByRole('button', { name: bruno.title }));

    expect(
      await pageScreen.findByRole('heading', { level: 1, name: fixtures.orders.title }),
    ).toBeInTheDocument();
    const [postedUrl, postedInit] = vi.mocked(fetch).mock.calls[1] ?? [];
    expect(postedUrl).toBe(bruno.href);
    expect(postedInit?.method).toBe('POST');
    expect(JSON.parse(String(postedInit?.body))).toEqual({
      profileId: bruno.fields?.[0]?.value,
    });
    await waitFor(() => expect(window.location.pathname).toBe('/orders'));
  });
});
