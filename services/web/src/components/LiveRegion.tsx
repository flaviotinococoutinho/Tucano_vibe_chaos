import type { ReactElement } from 'react';

/** An `aria-live="polite"` region for live screens: announces a status change without moving focus. */
export function LiveRegion({ message }: { readonly message: string }): ReactElement {
  return (
    <div aria-live="polite" role="status" className="visually-hidden">
      {message}
    </div>
  );
}
