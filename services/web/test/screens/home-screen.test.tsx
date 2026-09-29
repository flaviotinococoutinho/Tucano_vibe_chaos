import { screen as pageScreen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { toBrowserPath } from '../../src/hypermedia/index.ts';
import { HomeScreen } from '../../src/screens/index.ts';
import { entitiesOf, findAction, findLink, readString } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways } from '../support/mockFetch.ts';
import { renderWithHypermedia } from '../support/renderWithHypermedia.tsx';

const { home } = fixtures;
const cards = entitiesOf(home, 'item');

function renderHome(): void {
  mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: home }));
  renderWithHypermedia(<HomeScreen screen={home} />);
}

describe('the home of the platform', () => {
  it('lists every store in the order the BFF sent, each name the one link to the store', () => {
    renderHome();

    const list = within(pageScreen.getByRole('region', { name: 'Lojas' })).getByRole('list');
    const items = within(list).getAllByRole('listitem');
    expect(items).toHaveLength(cards.length);
    cards.forEach((card, index) => {
      const item = items[index];
      if (item === undefined) {
        throw new Error(`the store card ${index} is not on the page`);
      }
      const name = readString(card.properties, 'name') ?? '';
      const heading = within(item).getByRole('heading', { level: 2, name });
      expect(within(item).getAllByRole('link')).toHaveLength(1);
      expect(within(heading).getByRole('link', { name })).toHaveAttribute(
        'href',
        toBrowserPath(findLink(card.links, 'self')?.href ?? ''),
      );
    });
  });

  it('shows each store in its own palette, with its tagline and the call to enter it', () => {
    renderHome();

    for (const card of cards) {
      const name = readString(card.properties, 'name') ?? '';
      const item = pageScreen.getByRole('heading', { level: 2, name }).closest('li');
      if (item === null) {
        throw new Error(`the store "${name}" is not a card of the list`);
      }
      expect(item).toHaveAttribute('data-palette', readString(card.properties, 'palette'));
      expect(
        within(item).getByText(readString(card.properties, 'tagline') ?? ''),
      ).toBeInTheDocument();
      expect(within(item).getByText(findLink(card.links, 'self')?.title ?? '')).toHaveAttribute(
        'aria-hidden',
        'true',
      );
      expect(within(item).getByText(readString(card.properties, 'initial') ?? '')).toHaveAttribute(
        'data-palette',
        readString(card.properties, 'palette'),
      );
    }
  });

  it('keeps the tracking by code of every store on the home of the platform', () => {
    renderHome();

    const track = findAction(home.actions, 'track-by-code');
    const section = pageScreen.getByRole('region', { name: track?.title });
    expect(
      within(section)
        .getByRole('button', { name: track?.title ?? '' })
        .closest('form'),
    ).toBeInTheDocument();
  });

  it('draws a card with no palette it knows in the look of the platform', () => {
    const [first] = cards;
    if (first === undefined) {
      throw new Error('the home example has no store');
    }
    const unknown = { ...first, properties: { ...first.properties, palette: 'jandaia' } };
    const screen = { ...home, entities: [unknown] };
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: screen }));
    renderWithHypermedia(<HomeScreen screen={screen} />);

    const name = readString(first.properties, 'name') ?? '';
    expect(pageScreen.getByRole('heading', { level: 2, name }).closest('li')).not.toHaveAttribute(
      'data-palette',
    );
  });
});
