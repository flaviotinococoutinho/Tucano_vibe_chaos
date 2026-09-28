import type { ReactElement } from 'react';
import { formatInstant, type Instant } from '../siren/index.ts';

export function InstantText({ value }: { readonly value: Instant }): ReactElement {
  return (
    <time dateTime={value} className="numeric">
      {formatInstant(value)}
    </time>
  );
}
