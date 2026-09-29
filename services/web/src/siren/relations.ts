/**
 * The extension relations of the contract (RFC 8288 asks them to be URIs), each one landing
 * on its definition in `contracts/http/bff/README.md`. The registered ones (`self`, `up`,
 * `collection`, `item`, `next`, `prev`) go by their plain names.
 */
const CONTRACT =
  'https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md';

export const REL = {
  catalog: `${CONTRACT}#rel-catalog`,
  track: `${CONTRACT}#rel-track`,
  live: `${CONTRACT}#rel-live`,
  navigation: `${CONTRACT}#rel-navigation`,
  orders: `${CONTRACT}#rel-orders`,
  profiles: `${CONTRACT}#rel-profiles`,
  history: `${CONTRACT}#rel-history`,
} as const;
