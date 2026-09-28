import { screen as pageScreen, render, waitFor } from '@testing-library/react';
import userEvent, { type UserEvent } from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { HypermediaProvider } from '../../src/hypermedia/index.ts';
import { ScreenRouter } from '../../src/screens/index.ts';
import type { SirenAction } from '../../src/siren/index.ts';
import { fakeResponse } from '../support/fakeResponse.ts';
import { fixtures } from '../support/fixtures.ts';
import { mockFetchSequence } from '../support/mockFetch.ts';

function placeOrderAction(): SirenAction {
  const action = fixtures.checkout.actions?.find((candidate) => candidate.name === 'place-order');
  if (action === undefined) {
    throw new Error('fixture "checkout" has no place-order action');
  }
  return action;
}

// A leading-anchored regexp: the visible label also carries a required "*" marker (aria-hidden,
// so it never reaches an assistive technology's accessible name, but it is still part of the
// raw textContent getByLabelText matches against), and "Logradouro" alone would also match
// "Tipo de logradouro" under a case-insensitive substring search.
async function fillCheckoutForm(user: UserEvent): Promise<void> {
  await user.type(pageScreen.getByLabelText(/^Nome completo/), 'Ada Lovelace');
  await user.type(pageScreen.getByLabelText(/^E-mail/), 'ada@example.com');
  await user.type(pageScreen.getByLabelText(/^CEP/), '30160-011');
  await user.type(pageScreen.getByLabelText(/^Logradouro/), 'da Bahia');
  await user.type(pageScreen.getByLabelText(/^Número/), '1200');
  await user.type(pageScreen.getByLabelText(/^Cidade/), 'Belo Horizonte');
  await user.selectOptions(pageScreen.getByLabelText(/^Estado/), 'MG');
}

async function renderCheckoutAndSubmit(user: UserEvent): Promise<void> {
  render(
    <HypermediaProvider>
      <ScreenRouter />
    </HypermediaProvider>,
  );
  await pageScreen.findByRole('heading', { level: 1, name: fixtures.checkout.title });
  await fillCheckoutForm(user);
  await user.click(pageScreen.getByRole('button', { name: placeOrderAction().title }));
}

describe('place-order', () => {
  it('posts JSON with the hidden fields and follows the Location of a 201', async () => {
    const action = placeOrderAction();
    mockFetchSequence([
      fakeResponse({ url: 'http://localhost/bff/v1/checkout', body: fixtures.checkout }),
      fakeResponse({
        status: 201,
        url: action.href,
        headers: { Location: '/bff/v1/orders/0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d20' },
        body: fixtures.orderPendingPayment,
      }),
    ]);

    const user = userEvent.setup();
    await renderCheckoutAndSubmit(user);

    expect(
      await pageScreen.findByRole('heading', {
        level: 1,
        name: fixtures.orderPendingPayment.title,
      }),
    ).toBeInTheDocument();

    const fetchMock = vi.mocked(fetch);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    const [postedUrl, postedInit] = fetchMock.mock.calls[1] ?? [];
    expect(postedUrl).toBe(action.href);
    expect(postedInit?.method).toBe('POST');

    const body = JSON.parse(String(postedInit?.body));
    for (const field of (action.fields ?? []).filter((candidate) => candidate.type === 'hidden')) {
      expect(body[field.name]).toBe(field.value);
    }
    expect(body.name).toBe('Ada Lovelace');
    expect(body.email).toBe('ada@example.com');

    expect(window.location.pathname).toBe('/orders/0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d20');
  });

  it('shows a 422 next to each field, with the server detail, and focuses the first invalid field', async () => {
    const action = placeOrderAction();
    mockFetchSequence([
      fakeResponse({ url: 'http://localhost/bff/v1/checkout', body: fixtures.checkout }),
      fakeResponse({ status: 422, url: action.href, body: fixtures.validationProblem }),
    ]);

    const user = userEvent.setup();
    await renderCheckoutAndSubmit(user);

    // The checkout screen never navigates away: the form itself now carries the problem.
    expect(
      pageScreen.getByRole('heading', { level: 1, name: fixtures.checkout.title }),
    ).toBeInTheDocument();
    expect(await pageScreen.findByText(fixtures.validationProblem.detail)).toBeInTheDocument();

    const errors = fixtures.validationProblem.errors ?? {};
    for (const [name, messages] of Object.entries(errors)) {
      const field = action.fields?.find((candidate) => candidate.name === name);
      const input = document.getElementById(`field-${name}`);
      expect(input).toHaveAttribute('aria-invalid', 'true');
      for (const message of messages) {
        expect(pageScreen.getAllByText(message).length).toBeGreaterThan(0);
      }
      expect(field).toBeDefined();
    }

    const firstInvalid = (action.fields ?? []).find((field) => errors[field.name] !== undefined);
    expect(firstInvalid).toBeDefined();
    await waitFor(() => {
      expect(document.activeElement?.id).toBe(`field-${firstInvalid?.name}`);
    });
  });
});
