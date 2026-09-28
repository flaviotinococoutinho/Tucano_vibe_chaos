/**
 * The thoroughfare types the form offers. Commerce takes any type (ADR 0020); these are
 * the ones people pick most, and a select keeps the typing out of the way.
 */
export const THOROUGHFARE_TYPES = [
  'Rua',
  'Avenida',
  'Travessa',
  'Alameda',
  'Praça',
  'Estrada',
  'Rodovia',
] as const;

export type ThoroughfareType = (typeof THOROUGHFARE_TYPES)[number];

/** The 27 federative units by their UF, with the names IBGE uses. */
export const STATES = {
  AC: 'Acre',
  AL: 'Alagoas',
  AP: 'Amapá',
  AM: 'Amazonas',
  BA: 'Bahia',
  CE: 'Ceará',
  DF: 'Distrito Federal',
  ES: 'Espírito Santo',
  GO: 'Goiás',
  MA: 'Maranhão',
  MT: 'Mato Grosso',
  MS: 'Mato Grosso do Sul',
  MG: 'Minas Gerais',
  PA: 'Pará',
  PB: 'Paraíba',
  PR: 'Paraná',
  PE: 'Pernambuco',
  PI: 'Piauí',
  RJ: 'Rio de Janeiro',
  RN: 'Rio Grande do Norte',
  RS: 'Rio Grande do Sul',
  RO: 'Rondônia',
  RR: 'Roraima',
  SC: 'Santa Catarina',
  SP: 'São Paulo',
  SE: 'Sergipe',
  TO: 'Tocantins',
} as const;

export type Uf = keyof typeof STATES;

export const UFS = Object.keys(STATES) as Uf[];
