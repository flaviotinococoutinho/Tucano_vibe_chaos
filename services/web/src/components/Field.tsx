import type { ReactElement } from 'react';
import type { SirenField } from '../siren/index.ts';
import './Field.css';

export type FieldProps = {
  readonly field: SirenField;
  readonly errors?: readonly string[] | undefined;
};

const fieldId = (name: string): string => `field-${name}`;

/** One action field, rendered from what the server sent: label, kind, and every HTML hint it gave. */
export function Field({ field, errors }: FieldProps): ReactElement | null {
  if (field.type === 'hidden') {
    return <input type="hidden" name={field.name} defaultValue={field.value ?? ''} />;
  }

  const id = fieldId(field.name);
  const errorElementId = `${id}-error`;
  const hasError = errors !== undefined && errors.length > 0;

  return (
    <div className="field">
      <label htmlFor={id} className="field__label">
        {field.title ?? field.name}
        {field.required ? (
          <span aria-hidden="true" className="field__required">
            {' '}
            *
          </span>
        ) : null}
      </label>
      {field.type === 'select' ? (
        <select
          id={id}
          name={field.name}
          defaultValue={field.value ?? ''}
          required={field.required}
          aria-invalid={hasError || undefined}
          aria-describedby={hasError ? errorElementId : undefined}
          className="field__input"
        >
          {field.value === undefined && field.required ? (
            <option value="" disabled hidden>
              Selecione
            </option>
          ) : null}
          {(field.options ?? []).map((option) => (
            <option key={option.value} value={option.value}>
              {option.title}
            </option>
          ))}
        </select>
      ) : (
        <input
          id={id}
          name={field.name}
          type={field.type}
          defaultValue={field.value ?? ''}
          required={field.required}
          placeholder={field.placeholder}
          autoComplete={field.autocomplete}
          inputMode={field.inputmode}
          pattern={field.pattern}
          min={field.min}
          max={field.max}
          maxLength={field.maxlength}
          aria-invalid={hasError || undefined}
          aria-describedby={hasError ? errorElementId : undefined}
          className="field__input"
        />
      )}
      {hasError ? (
        <p id={errorElementId} className="field__error">
          {errors.join(' ')}
        </p>
      ) : null}
    </div>
  );
}
