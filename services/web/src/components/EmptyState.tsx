import type { ReactElement, ReactNode } from 'react';
import { Illustration } from './Illustration.tsx';
import './EmptyState.css';

export type EmptyStateProps = {
  readonly title: string;
  readonly children?: ReactNode;
};

/** An empty catalog page, an empty list on a generic screen: same mascot, different words. */
export function EmptyState({ title, children }: EmptyStateProps): ReactElement {
  return (
    <div className="empty-state">
      <Illustration name="empty" className="empty-state__illustration" />
      <p className="empty-state__title">{title}</p>
      {children}
    </div>
  );
}
