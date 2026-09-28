/**
 * The wire vocabulary of the BFF's Siren screens, as described in
 * `contracts/http/bff/README.md` and validated by `contracts/http/bff/siren.schema.json`.
 * This file only describes the shape of what arrives; it has no behavior of its own.
 */

/** A Siren class list: a screen is `["screen", "<name>"]`, a component is `["<component>"]`. */
export type SirenClass = readonly string[];

export type ActionMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export type FieldType = 'text' | 'email' | 'number' | 'hidden' | 'tel' | 'select';

export type FieldInputMode =
  | 'text'
  | 'numeric'
  | 'decimal'
  | 'email'
  | 'tel'
  | 'search'
  | 'url'
  | 'none';

export type FieldOption = {
  readonly value: string;
  readonly title: string;
};

export type SirenField = {
  readonly name: string;
  readonly type: FieldType;
  readonly title?: string;
  readonly value?: string | number;
  readonly required?: boolean;
  readonly placeholder?: string;
  readonly autocomplete?: string;
  readonly inputmode?: FieldInputMode;
  readonly pattern?: string;
  readonly min?: number;
  readonly max?: number;
  readonly maxlength?: number;
  readonly options?: readonly FieldOption[];
};

export type SirenAction = {
  readonly name: string;
  readonly title: string;
  readonly method: ActionMethod;
  readonly href: string;
  readonly type?: 'application/json';
  readonly class?: SirenClass;
  readonly fields?: readonly SirenField[];
};

export type SirenLink = {
  readonly rel: readonly string[];
  readonly href: string;
  readonly title?: string;
  readonly class?: SirenClass;
  readonly type?: string;
};

/** Properties are open content: each screen or component reads the shape it expects. */
export type SirenProperties = Readonly<Record<string, unknown>>;

export type SirenSubEntity = {
  readonly class: SirenClass;
  readonly rel: readonly string[];
  readonly title?: string;
  readonly properties?: SirenProperties;
  readonly links?: readonly SirenLink[];
  readonly actions?: readonly SirenAction[];
};

export type SirenScreen = {
  readonly class: SirenClass;
  readonly title: string;
  readonly properties?: SirenProperties;
  readonly entities?: readonly SirenSubEntity[];
  readonly links: readonly SirenLink[];
  readonly actions?: readonly SirenAction[];
};

/** How a status should feel. The web maps this to a badge style, never to meaning in code. */
export type Tone = 'neutral' | 'waiting' | 'info' | 'success' | 'danger';

export type Money = {
  readonly amount: number;
  readonly currency: string;
  readonly formatted: string;
};

/**
 * A short, tone-carrying message a screen wants read out: why an order was cancelled,
 * that a payment was just approved, that a product stopped selling. Any screen's
 * `properties.notice` may carry one, and the web renders it the same way everywhere.
 */
export type Notice = {
  readonly tone: Tone;
  readonly text: string;
};

/** An RFC 3339 instant in UTC, exactly as the BFF sends it, before the web formats it. */
export type Instant = string & { readonly __brand: 'Instant' };

export const Instant = {
  of(value: string): Instant {
    return value as Instant;
  },
};
