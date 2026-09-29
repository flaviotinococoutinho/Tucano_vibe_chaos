import { rel } from './links.ts';
import type { Link } from './siren.ts';

/** The query of a screen that comes in pages: `?page=`, from 1 on; Fastify refuses the rest with a 422. */
export const PAGE_QUERY = {
  type: 'object',
  properties: { page: { type: 'integer', minimum: 1, maximum: 10_000 } },
} as const;

export type PageFacts = {
  readonly page: number;
  readonly perPage: number;
  readonly total: number;
};

/** The link to this page, and to the pages around it when they exist. */
export function pageLinks(facts: PageFacts, at: (page: number) => string): Link[] {
  const lastPage = Math.max(1, Math.ceil(facts.total / Math.max(1, facts.perPage)));

  return [
    { rel: [rel.self], href: at(facts.page) },
    ...(facts.page < lastPage
      ? [{ rel: [rel.next], href: at(facts.page + 1), title: 'Próxima página' }]
      : []),
    ...(facts.page > 1
      ? [{ rel: [rel.prev], href: at(facts.page - 1), title: 'Página anterior' }]
      : []),
  ];
}
