import { describe, expect, it } from 'vitest';
import { CatalogScreen, componentFor, GenericScreen, HomeScreen } from '../../src/screens/index.ts';
import { fixtures } from '../support/fixtures.ts';

describe('componentFor', () => {
  it('picks the component registered for the screen class', () => {
    expect(componentFor(fixtures.home)).toBe(HomeScreen);
    expect(componentFor(fixtures.catalog)).toBe(CatalogScreen);
  });

  it('falls back to the generic renderer for a class it does not know', () => {
    const unknown = { ...fixtures.home, class: ['screen', 'something-new'] };
    expect(componentFor(unknown)).toBe(GenericScreen);
  });
});
