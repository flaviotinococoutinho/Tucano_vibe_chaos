import type { ReactElement } from 'react';
import type { Notice as NoticeData } from '../siren/index.ts';
import { toneStyle } from '../theme/index.ts';
import './Notice.css';

/**
 * Renders any screen's `properties.notice` the same way: why an order was cancelled, that a
 * payment just cleared, that a product stopped selling. One component, because the shape
 * (`{ tone, text }`) is the whole contract, whichever screen carries it.
 */
export function Notice({ notice }: { readonly notice: NoticeData }): ReactElement {
  const { modifier } = toneStyle(notice.tone);
  return <p className={`notice notice--${modifier}`}>{notice.text}</p>;
}
