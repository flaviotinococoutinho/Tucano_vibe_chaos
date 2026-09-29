import type { ReactElement } from 'react';
import { avatarColor } from '../theme/index.ts';
import './Avatar.css';

export type AvatarProps = {
  /** The initial the BFF gives, already the letter to show. */
  readonly initial: string;
  /** Picks the color: the same profile gets the same one everywhere. */
  readonly profileId?: string | undefined;
  readonly size?: 'small' | 'large';
};

/**
 * The round avatar of a profile. Decorative: the name always sits next to it, so a screen
 * reader hears the name once and not a lone letter before it.
 */
export function Avatar({ initial, profileId, size = 'small' }: AvatarProps): ReactElement {
  const color = profileId === undefined ? 'beak' : avatarColor(profileId);

  return (
    <span className={`avatar avatar--${size} avatar--${color}`} aria-hidden="true">
      {initial}
    </span>
  );
}
