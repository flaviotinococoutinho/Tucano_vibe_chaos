import type { ReactElement } from 'react';
import type { Palette } from '../theme/index.ts';
import './Monogram.css';

export type MonogramProps = {
  /** The initial the BFF gives, already the letter to show. */
  readonly initial: string;
  /** The palette of the store: the monogram wears it wherever it sits, even beside other stores. */
  readonly palette?: Palette | undefined;
  readonly size?: 'small' | 'medium' | 'large';
};

/**
 * The round monogram of a store, in the colors of its palette, with a ring inside that tells it
 * from the avatar of a profile. Decorative: the name of the store always sits next to it, so a
 * screen reader hears the name once and not a lone letter before it.
 */
export function Monogram({ initial, palette, size = 'small' }: MonogramProps): ReactElement {
  return (
    <span className={`monogram monogram--${size}`} data-palette={palette} aria-hidden="true">
      {initial}
    </span>
  );
}
