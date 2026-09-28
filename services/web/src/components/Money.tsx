import type { ReactElement } from 'react';
import type { Money as MoneyData } from '../siren/index.ts';

/** The BFF already formats money (`{ formatted: "R$ 159,90" }`); this just renders it consistently. */
export function Money({ value }: { readonly value: MoneyData }): ReactElement {
  return <span className="numeric">{value.formatted}</span>;
}
