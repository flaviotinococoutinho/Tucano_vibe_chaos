import type {
  Instant,
  Money,
  Notice,
  SirenAction,
  SirenClass,
  SirenLink,
  SirenProperties,
  SirenScreen,
  SirenSubEntity,
  Tone,
} from './types.ts';

const TONES: readonly Tone[] = ['neutral', 'waiting', 'info', 'success', 'danger'];

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
