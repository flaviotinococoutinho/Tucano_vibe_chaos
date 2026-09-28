import { type RenderOptions, type RenderResult, render } from '@testing-library/react';
import type { ReactElement } from 'react';
import { HypermediaProvider } from '../../src/hypermedia/index.ts';

/** Every component that follows a link or submits an action needs a `HypermediaProvider` above it. */
export function renderWithHypermedia(ui: ReactElement, options?: RenderOptions): RenderResult {
  return render(<HypermediaProvider>{ui}</HypermediaProvider>, options);
}
