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

/**
 * Base for errors raised by the domain. Subclasses are named after the problem
 * (InsufficientStock, TransitionNotAllowed) and say which category they are,
 * never which HTTP status to use.
 */
export abstract class DomainError extends Error {
  abstract readonly category: ErrorCategory;

  constructor(message: string) {
    super(message);
    this.name = new.target.name;
  }
}
