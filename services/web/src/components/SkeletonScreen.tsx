import type { ReactElement } from 'react';
import './SkeletonScreen.css';

/** Shown only while the very first screen has not arrived yet; every later load keeps the previous screen. */
export function SkeletonScreen(): ReactElement {
  return (
    <div className="skeleton" role="status">
      <span className="visually-hidden">Carregando...</span>
      <div className="skeleton__line skeleton__line--title" aria-hidden="true" />
      <div className="skeleton__line" aria-hidden="true" />
      <div className="skeleton__line" aria-hidden="true" />
      <div className="skeleton__line skeleton__line--short" aria-hidden="true" />
    </div>
  );
}
