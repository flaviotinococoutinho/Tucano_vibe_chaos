import { DomainError } from '../platform/domain-error.ts';
import type { Store } from '../upstream/index.ts';

/**
 * The shape of the slug of a store, as the catalog keeps it: immutable, and part of every
 * address of the store. Anything else is a store that does not exist, and nobody is asked.
 */
export const STORE_SLUG = /^[a-z][a-z0-9-]{1,30}$/;

export class StoreNotFound extends DomainError {
  readonly category = 'not_found';

  constructor() {
    super('Não encontrei essa loja.');
  }
}

/** How a store shows itself on a screen: what the web needs to draw its monogram and wear its palette. */
export type StoreBrand = {
  readonly slug: string;
  readonly name: string;
  readonly palette: string;
  /** The first letter of the name, for the round monogram. */
  readonly initial: string;
};

export function brandOf(store: Store): StoreBrand {
  return {
    slug: store.slug,
    name: store.name,
    palette: store.palette,
    initial: initialOf(store.name),
  };
}

function initialOf(name: string): string {
  const [first = '?'] = name;
  return first.toLocaleUpperCase('pt-BR');
}
