import { DomainError } from '../platform/domain-error.ts';

type FieldErrors = Readonly<Record<string, readonly string[]>>;

/** A submitted action with fields to fix: 422, with each message next to its field. */
export class InvalidForm extends DomainError {
  readonly category = 'invalid_input';

  constructor(fieldErrors: FieldErrors, detail = 'Alguns campos precisam de atenção.') {
    super(detail, { fieldErrors });
  }
}

type TextRule = {
  readonly maxlength: number;
  /** What to say when the field is missing or does not match the pattern. */
  readonly message: string;
  readonly pattern?: RegExp;
};

/**
 * Reads the JSON of a submitted action field by field and gathers one message for each
 * field that needs attention, so a person fixes the whole form at once instead of one
 * field per try. A field in error reads as a placeholder, and done() throws before any
 * placeholder is used.
 */
export class FormReader {
  readonly #body: Readonly<Record<string, unknown>>;
  readonly #errors: Record<string, string[]> = {};

  constructor(body: unknown) {
    this.#body = isRecord(body) ? body : {};
  }

  /** Required text, trimmed. */
  text(name: string, rule: TextRule): string {
    const value = this.#textOf(name);
    if (value === '' || (rule.pattern !== undefined && !rule.pattern.test(value))) {
      return this.#fail(name, rule.message, '');
    }
    if (value.length > rule.maxlength) {
      return this.#fail(name, `Use até ${rule.maxlength} caracteres.`, '');
    }
    return value;
  }

  /** Text a person may leave empty: empty reads as null. */
  optionalText(name: string, maxlength: number): string | null {
    const value = this.#textOf(name);
    if (value.length > maxlength) {
      return this.#fail(name, `Use até ${maxlength} caracteres.`, null);
    }
    return value === '' ? null : value;
  }

  /** One of the options the form offered. */
  choice<T extends string>(name: string, allowed: readonly T[], message: string): T {
    const value = this.#textOf(name);
    const chosen = allowed.find((option) => option === value);
    return chosen ?? this.#fail(name, message, allowed[0] as T);
  }

  /** A whole number within bounds, sent as a number or as its digits. */
  integer(name: string, min: number, max: number, message: string): number {
    const raw = this.#body[name];
    const value = typeof raw === 'string' && /^\d+$/.test(raw.trim()) ? Number(raw) : raw;
    if (typeof value === 'number' && Number.isInteger(value) && value >= min && value <= max) {
      return value;
    }
    return this.#fail(name, message, min);
  }

  /** Throws when any field needs attention; call it once, after reading them all. */
  done(): void {
    if (Object.keys(this.#errors).length > 0) {
      throw new InvalidForm(this.#errors);
    }
  }

  #textOf(name: string): string {
    const value = this.#body[name];
    if (typeof value === 'string') {
      return value.trim();
    }
    return typeof value === 'number' && Number.isFinite(value) ? String(value) : '';
  }

  #fail<T>(name: string, message: string, placeholder: T): T {
    this.#errors[name] = [...(this.#errors[name] ?? []), message];
    return placeholder;
  }
}

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
