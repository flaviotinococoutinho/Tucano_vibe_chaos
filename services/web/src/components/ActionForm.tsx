import { type FormEvent, type ReactElement, useState } from 'react';
import { type FieldValues, type SubmitOutcome, useHypermedia } from '../hypermedia/index.ts';
import type { SirenAction, SirenField } from '../siren/index.ts';
import { ErrorSummary } from './ErrorSummary.tsx';
import { Field } from './Field.tsx';
import { ProblemView } from './ProblemView.tsx';
import './ActionForm.css';

export type ActionFormProps = {
  readonly action: SirenAction;
  /** The weight of the button: one primary action per screen, the others secondary. */
  readonly variant?: 'primary' | 'secondary';
  /** A class of the place the form sits in, added to its own. */
  readonly className?: string;
};

/**
 * Turns an action's fields into a form. Submitting it never leaves the web building a URL:
 * `useHypermedia().submitAction` does the GET-with-query-string or POST-with-JSON dance and
 * hands back what happened. A 422 stays on this form (each message next to its field, an
 * error summary, focus on the first invalid one); a successful mutation swaps the whole
 * screen, which unmounts this form for us.
 */
export function ActionForm({
  action,
  variant = 'primary',
  className,
}: ActionFormProps): ReactElement {
  const { submitAction } = useHypermedia();
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<SubmitOutcome | null>(null);
  const fields = action.fields ?? [];

  const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
    event.preventDefault();
    if (submitting) {
      return;
    }
    const form = event.currentTarget;
    const values = collectValues(fields, form);

    setSubmitting(true);
    submitAction(action, values)
      .then((outcome) => {
        setResult(outcome);
        if (outcome.kind === 'validation') {
          focusFirstInvalidField(form, fields, outcome.problem.errors);
        }
      })
      .finally(() => setSubmitting(false));
  };

  return (
    // noValidate: the browser would stop at the first bad field, in its own words and bubble;
    // the BFF answers every field at once, in Portuguese, next to each one (the GOV.UK Design
    // System asks for the same). required, pattern and inputmode stay for the keyboard and
    // for assistive technology.
    <form
      className={className === undefined ? 'action-form' : `action-form ${className}`}
      onSubmit={handleSubmit}
      noValidate
    >
      {result?.kind === 'validation' ? (
        <ErrorSummary
          detail={result.problem.detail}
          errors={result.problem.errors}
          fields={fields}
        />
      ) : null}
      {result?.kind === 'problem' ? (
        <ProblemView problem={result.problem} variant="inline" />
      ) : null}
      {fields.map((field) => (
        <Field
          key={field.name}
          field={field}
          errors={result?.kind === 'validation' ? result.problem.errors[field.name] : undefined}
        />
      ))}
      <button type="submit" className={`button button--${variant}`} disabled={submitting}>
        {submitting ? 'Enviando...' : action.title}
      </button>
    </form>
  );
}

function collectValues(fields: readonly SirenField[], form: HTMLFormElement): FieldValues {
  const data = new FormData(form);
  const values: Record<string, string | number> = {};
  for (const field of fields) {
    if (field.type === 'hidden') {
      values[field.name] = field.value ?? '';
      continue;
    }
    const text = String(data.get(field.name) ?? '');
    values[field.name] = field.type === 'number' && text !== '' ? Number(text) : text;
  }
  return values;
}

function focusFirstInvalidField(
  form: HTMLFormElement,
  fields: readonly SirenField[],
  errors: Readonly<Record<string, readonly string[]>>,
): void {
  const firstInvalid = fields.find((field) => errors[field.name] !== undefined);
  const element = firstInvalid !== undefined ? form.elements.namedItem(firstInvalid.name) : null;
  if (element instanceof HTMLElement) {
    element.focus();
  }
}
