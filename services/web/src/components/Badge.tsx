import type { ReactElement, ReactNode } from 'react';
import type { Tone } from '../siren/index.ts';
import { toneStyle } from '../theme/index.ts';
import './Badge.css';

export type BadgeProps = {
  readonly tone: Tone;
  readonly children: ReactNode;
};

/** A status pill. The tone decides the look (`theme/tone.ts`); the label text is the server's. */
export function Badge({ tone, children }: BadgeProps): ReactElement {
  const { variant, modifier } = toneStyle(tone);
  return <span className={`badge badge--${variant} badge--${modifier}`}>{children}</span>;
}
