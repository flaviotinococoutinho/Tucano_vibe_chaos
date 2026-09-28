import { within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ActionForm } from '../../src/components/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchAlways } from '../support/mockFetch.ts';
import { renderWithHypermedia } from '../support/renderWithHypermedia.tsx';

describe('ActionForm', () => {
  it("renders a labeled input for every one of the action's visible fields", () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: fixtures.home }));
    const action = fixtures.checkout.actions?.find((candidate) => candidate.name === 'place-order');
    if (action === undefined) {
      throw new Error('fixture "checkout" has no place-order action');
    }

    const { container } = renderWithHypermedia(<ActionForm action={action} />);

    for (const field of action.fields ?? []) {
      if (field.type === 'hidden') {
        const hidden = container.querySelector(`input[type="hidden"][name="${field.name}"]`);
        expect(hidden).toHaveValue(String(field.value ?? ''));
        continue;
      }

      const input = document.getElementById(`field-${field.name}`);
      expect(input).not.toBeNull();
      if (field.required) {
        expect(input).toBeRequired();
      }
    }

    expect(container.querySelector('button[type="submit"]')).toHaveTextContent(action.title);
  });

  it("renders a select field's options from the fixture, including the server's default", () => {
    mockFetchAlways(fakeResponse({ url: 'http://localhost/bff/v1', body: fixtures.home }));
    const action = fixtures.checkout.actions?.find((candidate) => candidate.name === 'place-order');
    const field = action?.fields?.find((candidate) => candidate.name === 'thoroughfareType');
    if (action === undefined || field === undefined || field.options === undefined) {
      throw new Error('fixture "checkout" has no thoroughfareType select field');
    }

    renderWithHypermedia(<ActionForm action={action} />);

    const select = document.getElementById(`field-${field.name}`) as HTMLSelectElement;
    const options = within(select).getAllByRole('option');
    expect(options).toHaveLength(field.options.length);
    for (const option of field.options) {
      expect(within(select).getByRole('option', { name: option.title })).toBeInTheDocument();
    }
    expect(select).toHaveValue(field.value);
  });
});
