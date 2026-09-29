import { describe, expect, it } from 'vitest';
import { paletteOf } from '../../src/theme/index.ts';

describe('the palette of a store', () => {
  it('is one of the palettes of the design system, by its name', () => {
    expect(paletteOf('arara')).toBe('arara');
    expect(paletteOf('bemtevi')).toBe('bemtevi');
    expect(paletteOf('sabia')).toBe('sabia');
  });

  it('is none for a name the web does not know yet, so the look of the platform stays', () => {
    for (const unknown of ['jandaia', 'Arara', '', null, undefined, 42, { palette: 'arara' }]) {
      expect(paletteOf(unknown)).toBeUndefined();
    }
  });
});
