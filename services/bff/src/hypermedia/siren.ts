/**
 * The Siren vocabulary the BFF speaks to the web (contracts/http/bff). Plain data:
 * a screen is a value built by a pure function, and Fastify only serializes it.
 */
export const SIREN_JSON = 'application/vnd.siren+json';

export type Link = {
  readonly rel: readonly string[];
  readonly href: string;
  readonly title?: string;
  readonly class?: readonly string[];
};

export type Option = { readonly value: string; readonly title: string };

/** The input types of HTML, plus a select whose choices come in `options`. */
export type FieldType = 'text' | 'email' | 'number' | 'hidden' | 'tel' | 'select';

export type InputMode =
  | 'text'
  | 'numeric'
  | 'decimal'
  | 'email'
  | 'tel'
  | 'search'
  | 'url'
  | 'none';

/** A field of an action, with the attributes HTML already understands. */
export type Field = {
  readonly name: string;
  readonly type: FieldType;
  readonly title?: string;
  readonly value?: string | number;
  readonly required?: boolean;
  readonly placeholder?: string;
  readonly autocomplete?: string;
  readonly inputmode?: InputMode;
  readonly pattern?: string;
  readonly min?: number;
  readonly max?: number;
  readonly maxlength?: number;
  readonly options?: readonly Option[];
};

export type Action = {
  readonly name: string;
  /** The text of the button. */
  readonly title: string;
  readonly method: 'GET' | 'POST';
  readonly href: string;
  readonly type?: 'application/json';
  readonly fields: readonly Field[];
};

export type Properties = Readonly<Record<string, unknown>>;

export type Entity = {
  readonly class: readonly string[];
  readonly title?: string;
  readonly rel?: readonly string[];
  readonly properties?: Properties;
  readonly entities?: readonly Entity[];
  readonly links?: readonly Link[];
  readonly actions?: readonly Action[];
};
