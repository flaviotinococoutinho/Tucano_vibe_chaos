import type { Instant } from './types.ts';

// Money arrives already formatted by the BFF (`price.formatted`); only instants are left
// for the web to turn into something a person reads, in the reader's own locale.
const INSTANT_FORMAT = new Intl.DateTimeFormat('pt-BR', {
  dateStyle: 'short',
  timeStyle: 'short',
});

/** `2026-09-28T05:10:11.000Z` becomes `28/09/2026, 05:10` (America/Sao_Paulo-less, browser-local). */
export function formatInstant(instant: Instant): string {
  return INSTANT_FORMAT.format(new Date(instant));
}
