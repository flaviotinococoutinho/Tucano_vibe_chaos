/**
 * The city of the sorting hub Tucano's partners keep in each state. Not every state has one
 * in the lab yet, and a pickup can still name any of the 27: `hubFor` falls back to a bare
 * name for the ones missing here.
 */
const HUB_CITIES: Readonly<Record<string, string>> = {
  SP: 'Cajamar',
  MG: 'Contagem',
  RJ: 'Duque de Caxias',
  ES: 'Serra',
  PR: 'Araucária',
  SC: 'Itajaí',
  RS: 'Cachoeirinha',
  BA: 'Simões Filho',
  PE: 'Jaboatão dos Guararapes',
  CE: 'Maracanaú',
  DF: 'Águas Claras',
  GO: 'Aparecida de Goiânia',
  PA: 'Ananindeua',
  AM: 'Manaus',
};

/** The sorting hub of a state: a named city when the lab has one, or `Hub <UF>` otherwise. */
export function hubFor(state: string): string {
  const city = HUB_CITIES[state];

  return city === undefined ? `Hub ${state}` : `Hub ${city} (${state})`;
}
