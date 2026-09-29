/**
 * The colors a profile avatar can take: the palette pairs whose initial reads at 4.5:1 or
 * more in both themes (the avatar brings its own background, so the theme does not change it).
 * See `src/styles/tokens.css` for the contrast of each pair.
 */
const AVATAR_COLORS = ['beak', 'sun', 'teal-light', 'coral', 'teal-deep', 'coral-deep'] as const;

export type AvatarColor = (typeof AVATAR_COLORS)[number];

/**
 * The same profile always gets the same color, on every screen and in the header, from its
 * id alone (FNV-1a, 32 bits): the web keeps nothing, and two profiles of a browser rarely match.
 */
export function avatarColor(profileId: string): AvatarColor {
  let hash = 0x811c9dc5;
  for (let index = 0; index < profileId.length; index += 1) {
    hash ^= profileId.charCodeAt(index);
    hash = Math.imul(hash, 0x01000193);
  }
  return AVATAR_COLORS[(hash >>> 0) % AVATAR_COLORS.length] ?? 'beak';
}
