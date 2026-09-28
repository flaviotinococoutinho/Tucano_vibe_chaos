import type { ReactElement } from 'react';
import type { SirenField } from '../siren/index.ts';
import './ErrorSummary.css';

export type ErrorSummaryProps = {
  /** The problem's own `detail`, in the server's words (e.g. "Alguns campos precisam de atenção."). */
  readonly detail: string;
  readonly errors: Readonly<Record<string, readonly string[]>>;
  readonly fields: readonly SirenField[];
};

/** `role="alert"`: a submission that failed validation is worth an immediate announcement. */
export function ErrorSummary({ detail, errors, fields }: ErrorSummaryProps): ReactElement {
  const titleOf = (name: string): string =>
    fields.find((field) => field.name === name)?.title ?? name;

  return (
    <div className="error-summary" role="alert">
      <p className="error-summary__detail">{detail}</p>
      <ul className="error-summary__list">
        {Object.entries(errors).map(([name, messages]) => (
          <li key={name}>
            <a href={`#field-${name}`}>
              {titleOf(name)}: {messages.join(' ')}
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}
