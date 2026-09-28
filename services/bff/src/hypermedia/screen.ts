import type { Action, Entity, Link, Properties } from './siren.ts';

/**
 * How a status should feel, never which color it gets: the web owns the palette, and
 * a new tone is a conversation between both sides, not a hex code in a response.
 */
export type Tone = 'neutral' | 'waiting' | 'info' | 'success' | 'danger';

/** A sentence the screen wants read first, like why an order was cancelled. */
export type Notice = { readonly tone: Tone; readonly text: string };

type ScreenParts = {
  readonly title: string;
  readonly properties?: Properties;
  readonly entities?: readonly Entity[];
  readonly actions?: readonly Action[];
  readonly links: readonly Link[];
  /** Asks the web to fetch the screen again from its self link after this many seconds. */
  readonly refreshAfterSeconds?: number;
};

/**
 * A screen: `["screen", "<name>"]`, plus `"live"` when it asks to be fetched again.
 * Empty lists stay out, so the web never has to tell "none" from "missing".
 */
export function screen(name: string, parts: ScreenParts): Entity {
  const { refreshAfterSeconds } = parts;
  const live = refreshAfterSeconds !== undefined;

  return {
    class: live ? ['screen', name, 'live'] : ['screen', name],
    title: parts.title,
    properties: live ? { ...parts.properties, refreshAfterSeconds } : (parts.properties ?? {}),
    ...(parts.entities?.length ? { entities: parts.entities } : {}),
    ...(parts.actions?.length ? { actions: parts.actions } : {}),
    links: parts.links,
  };
}

type ComponentParts = {
  readonly rel: readonly string[];
  readonly properties: Properties;
  readonly links?: readonly Link[];
};

/** A piece embedded in a screen, such as a product card: `["<component>"]` with its rel. */
export function component(name: string, parts: ComponentParts): Entity {
  return {
    class: [name],
    rel: parts.rel,
    properties: parts.properties,
    ...(parts.links?.length ? { links: parts.links } : {}),
  };
}
