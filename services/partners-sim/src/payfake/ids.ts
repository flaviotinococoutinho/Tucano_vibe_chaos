import { v7 as uuidv7 } from 'uuid';

const CROCKFORD_BASE32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const LENGTH = 26;

/**
 * Ids in the style PSPs hand out: a type prefix and 26 characters. The characters are a
 * UUIDv7 in Crockford's Base32, the alphabet of the tracking code, so ids sort by creation.
 */
export function newId(prefix: 'ch' | 're' | 'evt'): string {
  let bits = BigInt(`0x${uuidv7().replaceAll('-', '')}`);
  let encoded = '';
  for (let index = 0; index < LENGTH; index += 1) {
    encoded = CROCKFORD_BASE32.charAt(Number(bits & 31n)) + encoded;
    bits >>= 5n;
  }

  return `${prefix}_${encoded}`;
}
