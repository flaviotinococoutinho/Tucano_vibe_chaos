import { useEffect } from 'react';

/**
 * The palettes of the design system a store can wear (ADR 0031). A store picks one by name and
 * the web owns its colors, each pair verified in `src/styles/tokens.css`, so no color ever comes
 * from a database.
 */
const PALETTES = ['arara', 'bemtevi', 'sabia'] as const;

export type Palette = (typeof PALETTES)[number];

/**
 * A palette this web knows, or `undefined`: a palette the BFF sends before the web learns it
 * wears the look of the platform, never a half-drawn one.
 */
export function paletteOf(value: unknown): Palette | undefined {
  return PALETTES.find((palette) => palette === value);
}

/**
 * Dresses the page in the palette of the store on show, or in the look of the platform when
 * there is none. Mirrored to `<html data-palette>`, which `src/styles/tokens.css` switches on,
 * and taken off when the page leaves the store.
 */
export function usePagePalette(palette: Palette | undefined): void {
  useEffect(() => {
    if (palette === undefined) {
      return;
    }
    const root = document.documentElement;
    root.dataset.palette = palette;
    return () => {
      delete root.dataset.palette;
    };
  }, [palette]);
}
