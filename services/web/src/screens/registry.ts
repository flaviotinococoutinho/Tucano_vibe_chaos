import type { ComponentType } from 'react';
import { type SirenScreen, screenClassOf } from '../siren/index.ts';
import { CatalogScreen } from './CatalogScreen.tsx';
import { CheckoutScreen } from './CheckoutScreen.tsx';
import { GenericScreen } from './GenericScreen.tsx';
import { HomeScreen } from './HomeScreen.tsx';
import { OrderScreen } from './OrderScreen.tsx';
import { ProductScreen } from './ProductScreen.tsx';
import { TrackingScreen } from './TrackingScreen.tsx';

export type ScreenComponentProps = { readonly screen: SirenScreen };
export type ScreenComponent = ComponentType<ScreenComponentProps>;

/** Screen class to component. A class not listed here still works, through `GenericScreen`. */
const REGISTRY: Readonly<Record<string, ScreenComponent>> = {
  home: HomeScreen,
  catalog: CatalogScreen,
  product: ProductScreen,
  checkout: CheckoutScreen,
  order: OrderScreen,
  tracking: TrackingScreen,
};

export function componentFor(screen: SirenScreen): ScreenComponent {
  return REGISTRY[screenClassOf(screen)] ?? GenericScreen;
}
