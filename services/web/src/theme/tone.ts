import type { Tone } from '../siren/index.ts';

/**
 * Tone says how a status should feel; this table says what that looks like. It is the only
 * place that turns a `Tone` into a visual, so a badge and a notice banner never disagree.
 *
 * `filled` is reserved for the two tones worth a strong, solid chip (`waiting`, `success`);
 * the rest read as a quieter outline in the tone's own color. See `src/styles/tokens.css`
 * for the actual colors and the contrast notes behind each one.
 */
export type ToneVariant = 'outline' | 'filled';

export type ToneStyle = {
  readonly variant: ToneVariant;
  /** The modifier class a component adds to its own block, e.g. `badge--danger`. */
  readonly modifier: string;
};

const TONE_STYLES: Readonly<Record<Tone, ToneStyle>> = {
  neutral: { variant: 'outline', modifier: 'neutral' },
  waiting: { variant: 'filled', modifier: 'waiting' },
  info: { variant: 'outline', modifier: 'info' },
  success: { variant: 'filled', modifier: 'success' },
  danger: { variant: 'outline', modifier: 'danger' },
};

export function toneStyle(tone: Tone): ToneStyle {
  return TONE_STYLES[tone];
}
