import { describe, expect, it } from 'vitest';
import {
  CatalogScreen,
  componentFor,
  GenericScreen,
  HomeScreen,
  OrderScreen,
  OrdersScreen,
  ProfilesScreen,
} from '../../src/screens/index.ts';
import { fixtures } from '../support/fixtures.ts';

describe('componentFor', () => {
  it('picks the component registered for the screen class', () => {
    expect(componentFor(fixtures.home)).toBe(HomeScreen);
    expect(componentFor(fixtures.catalog)).toBe(CatalogScreen);
    expect(componentFor(fixtures.orderDelivered)).toBe(OrderScreen);
    expect(componentFor(fixtures.orders)).toBe(OrdersScreen);
    expect(componentFor(fixtures.profiles)).toBe(ProfilesScreen);
  });

  it('falls back to the generic renderer for a class it does not know', () => {
    const unknown = { ...fixtures.home, class: ['screen', 'something-new'] };
    expect(componentFor(unknown)).toBe(GenericScreen);
  });
});
