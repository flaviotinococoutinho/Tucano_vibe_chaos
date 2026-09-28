/**
 * What kind of problem a domain error is, in business terms. Adapters decide what
 * that means for their protocol (HTTP status, retry or DLQ for messages).
 */
export type ErrorCategory =
  | 'not_found'
  | 'conflict'
  | 'invalid_input'
  | 'forbidden'
  | 'unavailable';

/** What an error can add for the caller, besides its message. */
export type DomainErrorDetails = {
  /** Seconds until trying again makes sense; HTTP sends it as Retry-After. */
  readonly retryAfterSeconds?: number;
  /** Messages per field, for a request a person fixes in a form; HTTP sends them as `errors`. */
  readonly fieldErrors?: Readonly<Record<string, readonly string[]>>;
};

/**
 * Base for errors raised by the domain. Subclasses are named after the problem
 * (InsufficientStock, TransitionNotAllowed) and say which category they are,
 * never which HTTP status to use.
 */
export abstract class DomainError extends Error {
  abstract readonly category: ErrorCategory;
  readonly retryAfterSeconds: number | undefined;
  readonly fieldErrors: Readonly<Record<string, readonly string[]>> | undefined;

  constructor(message: string, details: DomainErrorDetails = {}) {
    super(message);
    this.name = new.target.name;
    this.retryAfterSeconds = details.retryAfterSeconds;
    this.fieldErrors = details.fieldErrors;
  }
}
