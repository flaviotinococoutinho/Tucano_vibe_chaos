import type { ReactElement } from 'react';
import type { SirenField } from '../siren/index.ts';
import './ErrorSummary.css';

export type ErrorSummaryProps = {
  /** The problem's own `detail`, in the server's words (e.g. "Alguns campos precisam de atenção."). */
  readonly detail: string;
  readonly errors: Readonly<Record<string, readonly string[]>>;
  readonly fields: readonly SirenField[];
};

/**
 * `role="alert"`: a submission that failed validation is worth an immediate announcement.
 * A message about a field the person can see links to it; one about a hidden field (a key,
 * the profile a button chose) reads as a plain sentence, since there is nothing to go fix.
 */
export function ErrorSummary({ detail, errors, fields }: ErrorSummaryProps): ReactElement {
  return (
    <div className="error-summary" role="alert">
      <p className="error-summary__detail">{detail}</p>
      <ul className="error-summary__list">
        {Object.entries(errors).map(([name, messages]) => {
          const field = fields.find((candidate) => candidate.name === name);
          const text = messages.join(' ');
          return (
            <li key={name}>
              {field !== undefined && field.type !== 'hidden' ? (
                <a href={`#field-${name}`}>
                  {field.title ?? name}: {text}
                </a>
              ) : (
                text
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}
