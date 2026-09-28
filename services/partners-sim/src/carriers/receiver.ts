import { between, pick, type Random } from '../chance.ts';

const FIRST_NAMES = [
  'Ana',
  'Bruno',
  'Carla',
  'Diego',
  'Elisa',
  'Fábio',
  'Gabriela',
  'Heitor',
  'Isabela',
  'João',
  'Larissa',
  'Marcos',
  'Nina',
  'Otávio',
  'Patrícia',
  'Rafael',
  'Sabrina',
  'Tiago',
  'Valentina',
  'Wesley',
] as const;

const LAST_NAMES = [
  'Almeida',
  'Barros',
  'Costa',
  'Duarte',
  'Ferreira',
  'Gomes',
  'Henrique',
  'Junqueira',
  'Lima',
  'Martins',
  'Nogueira',
  'Oliveira',
  'Pereira',
  'Queiroz',
  'Ramos',
  'Souza',
  'Teixeira',
  'Vieira',
] as const;

/** Whoever the courier says took the parcels: at most 120 characters, well inside the limit. */
export function randomReceiverName(random: Random): string {
  return `${pick(random, FIRST_NAMES)} ${pick(random, LAST_NAMES)}`;
}

/** A CPF, masked the way Brazilian forms show it: `123.456.789-09`, 14 characters. */
export function randomReceiverDocument(random: Random): string {
  const digits = Array.from({ length: 9 }, () => between(random, 0, 9));
  const firstCheck = checkDigit(digits);
  const allDigits = [...digits, firstCheck, checkDigit([...digits, firstCheck])];
  const text = allDigits.join('');

  return `${text.slice(0, 3)}.${text.slice(3, 6)}.${text.slice(6, 9)}-${text.slice(9, 11)}`;
}

/** The CPF check digit algorithm: each digit weighted by its distance from the end, mod 11. */
function checkDigit(digits: readonly number[]): number {
  let sum = 0;
  let weight = digits.length + 1;
  for (const digit of digits) {
    sum += digit * weight;
    weight -= 1;
  }
  const remainder = (sum * 10) % 11;

  return remainder >= 10 ? 0 : remainder;
}
