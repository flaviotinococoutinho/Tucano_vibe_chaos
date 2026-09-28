import type { ReactElement } from 'react';
import './ProgressBar.css';

/**
 * The thin bar at the top of the page while a later screen loads. Purely visual: the
 * previous screen stays fully shown and interactive underneath it, so it carries no ARIA
 * role of its own; the heading of the screen that eventually lands is what a screen reader
 * user hears about.
 */
export function ProgressBar({ active }: { readonly active: boolean }): ReactElement | null {
  if (!active) {
    return null;
  }
  return <div className="progress-bar" aria-hidden="true" />;
}
