import type { ComponentType } from 'react';
import { type SirenScreen, screenClassOf } from '../siren/index.ts';
import { CatalogScreen } from './CatalogScreen.tsx';
import { CheckoutScreen } from './CheckoutScreen.tsx';
import { GenericScreen } from './GenericScreen.tsx';
import { HomeScreen } from './HomeScreen.tsx';
import { OrderScreen } from './OrderScreen.tsx';
import { OrdersScreen } from './OrdersScreen.tsx';
import { ProductScreen } from './ProductScreen.tsx';
import { ProfilesScreen } from './ProfilesScreen.tsx';
import { StoreScreen } from './StoreScreen.tsx';
import { TrackingScreen } from './TrackingScreen.tsx';

export type ScreenComponentProps = { readonly screen: SirenScreen };
export type ScreenComponent = ComponentType<ScreenComponentProps>;

/** Screen class to component. A class not listed here still works, through `GenericScreen`. */
const REGISTRY: Readonly<Record<string, ScreenComponent>> = {
  home: HomeScreen,
  store: StoreScreen,
  catalog: CatalogScreen,
  product: ProductScreen,
  checkout: CheckoutScreen,
  order: OrderScreen,
  orders: OrdersScreen,
  profiles: ProfilesScreen,
  tracking: TrackingScreen,
};

export function componentFor(screen: SirenScreen): ScreenComponent {
  return REGISTRY[screenClassOf(screen)] ?? GenericScreen;
}
