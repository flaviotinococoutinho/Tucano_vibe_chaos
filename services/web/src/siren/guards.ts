import type {
  Instant,
  Milestone,
  MilestoneState,
  Money,
  Notice,
  Shopper,
  SirenAction,
  SirenClass,
  SirenLink,
  SirenProperties,
  SirenScreen,
  SirenSubEntity,
  StoreBrand,
  Tone,
} from './types.ts';

const TONES: readonly Tone[] = ['neutral', 'waiting', 'info', 'success', 'danger'];
const MILESTONE_STATES: readonly MilestoneState[] = ['done', 'current', 'upcoming', 'stopped'];

/** Every screen carries `class: ["screen", "<name>", ...]`; this reads the `<name>`. */
export function screenClassOf(screen: SirenScreen): string {
  return screen.class.find((name) => name !== 'screen' && name !== 'live') ?? 'screen';
}

export function isLive(screen: SirenScreen): boolean {
  return includesClass(screen.class, 'live');
}

export function hasClass(entity: { readonly class?: SirenClass }, name: string): boolean {
  return includesClass(entity.class, name);
}

function includesClass(list: SirenClass | undefined, name: string): boolean {
  return list?.includes(name) ?? false;
}

/** The first link of a relation, in document order. `rel` may be a full URI for domain relations. */
export function findLink(
  links: readonly SirenLink[] | undefined,
  rel: string,
): SirenLink | undefined {
  return links?.find((link) => link.rel.includes(rel));
}

export function findAction(
  actions: readonly SirenAction[] | undefined,
  name: string,
): SirenAction | undefined {
  return actions?.find((action) => action.name === name);
}

export function entitiesOf(screen: SirenScreen, rel: string = 'item'): readonly SirenSubEntity[] {
  return screen.entities?.filter((entity) => entity.rel.includes(rel)) ?? [];
}

/** The first embedded component of a relation, like the `navigation` every screen carries. */
export function findEntity(
  entities: readonly SirenSubEntity[] | undefined,
  rel: string,
): SirenSubEntity | undefined {
  return entities?.find((entity) => entity.rel.includes(rel));
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function readString(
  properties: SirenProperties | undefined,
  key: string,
): string | undefined {
  const value = properties?.[key];
  return typeof value === 'string' ? value : undefined;
}

export function readNumber(
  properties: SirenProperties | undefined,
  key: string,
): number | undefined {
  const value = properties?.[key];
  return typeof value === 'number' ? value : undefined;
}

export function readBoolean(
  properties: SirenProperties | undefined,
  key: string,
): boolean | undefined {
  const value = properties?.[key];
  return typeof value === 'boolean' ? value : undefined;
}

function isMilestone(value: unknown): value is Milestone {
  return (
    isRecord(value) &&
    typeof value.label === 'string' &&
    typeof value.state === 'string' &&
    (MILESTONE_STATES as readonly string[]).includes(value.state) &&
    (value.at === undefined || typeof value.at === 'string')
  );
}

/**
 * Reads an order's `progress`. A milestone in a shape this web does not know yet is left out,
 * and the rest still draw: the stepper shows less, never something wrong.
 */
export function readProgress(
  properties: SirenProperties | undefined,
  key: string = 'progress',
): readonly Milestone[] | undefined {
  const value = properties?.[key];
  return Array.isArray(value) ? value.filter(isMilestone) : undefined;
}

/**
 * Reads `properties.shopper` of the navigation: the shopper, `null` when nobody is shopping
 * yet (a browser with no session), `undefined` when the shape is not one this web knows.
 */
export function readShopper(properties: SirenProperties | undefined): Shopper | null | undefined {
  const value = properties?.shopper;
  if (value === null) {
    return null;
  }
  if (!isRecord(value) || typeof value.label !== 'string' || typeof value.initial !== 'string') {
    return undefined;
  }
  return typeof value.profileId === 'string'
    ? { profileId: value.profileId, label: value.label, initial: value.initial }
    : { label: value.label, initial: value.initial };
}

/**
 * Reads `properties.store` of the navigation: the store the screen is in, `null` on a screen of
 * the platform, `undefined` when the shape is not one this web knows.
 */
export function readStore(properties: SirenProperties | undefined): StoreBrand | null | undefined {
  const value = properties?.store;
  if (value === null) {
    return null;
  }
  if (
    !isRecord(value) ||
    typeof value.slug !== 'string' ||
    typeof value.name !== 'string' ||
    typeof value.palette !== 'string' ||
    typeof value.initial !== 'string'
  ) {
    return undefined;
  }
  return { slug: value.slug, name: value.name, palette: value.palette, initial: value.initial };
}

export function isMoney(value: unknown): value is Money {
  return (
    isRecord(value) &&
    typeof value.amount === 'number' &&
    typeof value.currency === 'string' &&
    typeof value.formatted === 'string'
  );
}

export function readMoney(properties: SirenProperties | undefined, key: string): Money | undefined {
  const value = properties?.[key];
  return isMoney(value) ? value : undefined;
}

export function isTone(value: unknown): value is Tone {
  return typeof value === 'string' && (TONES as readonly string[]).includes(value);
}

export function readTone(properties: SirenProperties | undefined, key: string): Tone | undefined {
  const value = properties?.[key];
  return isTone(value) ? value : undefined;
}

export function readInstant(
  properties: SirenProperties | undefined,
  key: string,
): Instant | undefined {
  const value = properties?.[key];
  return typeof value === 'string' ? (value as Instant) : undefined;
}

/**
 * Reads `properties.notice`, defensively: a screen the web does not know yet can still
 * carry one, and it renders the same way, because the shape is the whole contract.
 */
export function readNotice(properties: SirenProperties | undefined): Notice | undefined {
  const value = properties?.notice;
  return isRecord(value) && isTone(value.tone) && typeof value.text === 'string'
    ? { tone: value.tone, text: value.text }
    : undefined;
}
