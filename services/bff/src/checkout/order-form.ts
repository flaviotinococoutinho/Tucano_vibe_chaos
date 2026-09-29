import {
  type Action,
  FormReader,
  hidden,
  href,
  InvalidForm,
  idempotencyKeyField,
} from '../hypermedia/index.ts';
import type { DomainError } from '../platform/domain-error.ts';
import { SKU } from '../storefront/index.ts';
import { MAX_UNITS_PER_ITEM, type NewOrder, type Refusal } from '../upstream/index.ts';
import { STATES, THOROUGHFARE_TYPES, type ThoroughfareType, UFS, type Uf } from './address.ts';
import { FormAlreadyUsed, OrderNotPlaced, ProductOutOfLine } from './errors.ts';

/** The limits of Commerce (PlaceOrderRequest), so the browser stops a value before the service does. */
const MAX = {
  name: 120,
  email: 254,
  postalCode: 9,
  thoroughfareName: 160,
  number: 20,
  complement: 80,
  neighborhood: 80,
  municipality: 80,
} as const;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const POSTAL_CODE = /^[0-9]{5}-?[0-9]{3}$/;

/** A submitted order form, every field read and checked. */
export type OrderForm = {
  readonly idempotencyKey: string;
  readonly sku: string;
  readonly quantity: number;
  readonly name: string;
  readonly email: string;
  readonly postalCode: string;
  readonly thoroughfareType: ThoroughfareType;
  readonly thoroughfareName: string;
  readonly number: string;
  readonly complement: string | null;
  readonly neighborhood: string | null;
  readonly municipality: string;
  readonly state: Uf;
};

/** The form of the order, in the order a person fills it, with what HTML needs to help. */
export function placeOrderAction(sku: string, quantity: number, key: string): Action {
  return {
    name: 'place-order',
    title: 'Fazer pedido',
    method: 'POST',
    href: href('/orders'),
    type: 'application/json',
    fields: [
      idempotencyKeyField(key),
      hidden('sku', sku),
      hidden('quantity', quantity),
      {
        name: 'name',
        type: 'text',
        title: 'Nome completo',
        required: true,
        autocomplete: 'name',
        maxlength: MAX.name,
      },
      {
        name: 'email',
        type: 'email',
        title: 'E-mail',
        required: true,
        autocomplete: 'email',
        inputmode: 'email',
        maxlength: MAX.email,
      },
      {
        name: 'postalCode',
        type: 'text',
        title: 'CEP',
        required: true,
        autocomplete: 'postal-code',
        inputmode: 'numeric',
        pattern: POSTAL_CODE.source,
        maxlength: MAX.postalCode,
        placeholder: '30160-011',
      },
      {
        name: 'thoroughfareType',
        type: 'select',
        title: 'Tipo de logradouro',
        required: true,
        value: 'Rua',
        options: THOROUGHFARE_TYPES.map((type) => ({ value: type, title: type })),
      },
      {
        name: 'thoroughfareName',
        type: 'text',
        title: 'Logradouro',
        required: true,
        autocomplete: 'address-line1',
        maxlength: MAX.thoroughfareName,
        placeholder: 'da Bahia',
      },
      {
        name: 'number',
        type: 'text',
        title: 'Número',
        required: true,
        maxlength: MAX.number,
        placeholder: '1200 ou KM 500',
      },
      {
        name: 'complement',
        type: 'text',
        title: 'Complemento',
        autocomplete: 'address-line2',
        maxlength: MAX.complement,
      },
      {
        name: 'neighborhood',
        type: 'text',
        title: 'Bairro',
        autocomplete: 'address-level3',
        maxlength: MAX.neighborhood,
      },
      {
        name: 'municipality',
        type: 'text',
        title: 'Cidade',
        required: true,
        autocomplete: 'address-level2',
        maxlength: MAX.municipality,
      },
      {
        name: 'state',
        type: 'select',
        title: 'Estado',
        required: true,
        autocomplete: 'address-level1',
        options: UFS.map((uf) => ({ value: uf, title: STATES[uf] })),
      },
    ],
  };
}

/** Reads a submitted order form; every field that needs attention comes back in one 422. */
export function readOrderForm(body: unknown): OrderForm {
  const form = new FormReader(body);
  const again = 'Volte ao produto e comece o pedido de novo.';
  const read: OrderForm = {
    idempotencyKey: form.text('idempotencyKey', { maxlength: 36, pattern: UUID, message: again }),
    sku: form.text('sku', { maxlength: 32, pattern: SKU, message: again }),
    quantity: form.integer('quantity', 1, MAX_UNITS_PER_ITEM, again),
    name: form.text('name', { maxlength: MAX.name, message: 'Informe seu nome completo.' }),
    email: form.text('email', {
      maxlength: MAX.email,
      pattern: EMAIL,
      message: 'Informe um e-mail válido.',
    }),
    postalCode: form.text('postalCode', {
      maxlength: MAX.postalCode,
      pattern: POSTAL_CODE,
      message: 'O CEP tem 8 números.',
    }),
    thoroughfareType: form.choice(
      'thoroughfareType',
      THOROUGHFARE_TYPES,
      'Escolha o tipo de logradouro.',
    ),
    thoroughfareName: form.text('thoroughfareName', {
      maxlength: MAX.thoroughfareName,
      message: 'Informe o nome da rua, da avenida ou da estrada.',
    }),
    number: form.text('number', {
      maxlength: MAX.number,
      message: 'Informe o número, ou S/N quando não houver.',
    }),
    complement: form.optionalText('complement', MAX.complement),
    neighborhood: form.optionalText('neighborhood', MAX.neighborhood),
    municipality: form.text('municipality', {
      maxlength: MAX.municipality,
      message: 'Informe a cidade.',
    }),
    state: form.choice('state', UFS, 'Escolha o estado.'),
  };
  form.done();

  return read;
}

/**
 * The order in the words of Commerce, for the profile shopping now. The address goes as
 * pieces (ADR 0020): the thoroughfare as a type and a name, and the territory from the
 * state down. The IBGE code of the city stays null: the form asks for the name, and
 * Commerce accepts that.
 */
export function newOrderOf(form: OrderForm, customerId: string): NewOrder {
  return {
    customer: { id: customerId, name: form.name, email: form.email },
    shippingAddress: {
      thoroughfare: { type: form.thoroughfareType, name: form.thoroughfareName },
      number: form.number,
      complement: form.complement,
      divisions: [
        { kind: 'state', code: form.state, name: STATES[form.state] },
        { kind: 'municipality', code: null, name: form.municipality },
        ...(form.neighborhood === null
          ? []
          : [{ kind: 'neighborhood' as const, code: null, name: form.neighborhood }]),
      ],
      postalCode: form.postalCode,
    },
    items: [{ sku: form.sku, quantity: form.quantity }],
  };
}

/** Which field of the form each field of Commerce came from. */
const FORM_FIELDS: ReadonlyArray<readonly [RegExp, string]> = [
  [/^customer\.name$/, 'name'],
  [/^customer\.email$/, 'email'],
  [/^shippingAddress\.postalCode$/, 'postalCode'],
  [/^shippingAddress\.thoroughfare\.type$/, 'thoroughfareType'],
  [/^shippingAddress\.thoroughfare\.name$/, 'thoroughfareName'],
  [/^shippingAddress\.number$/, 'number'],
  [/^shippingAddress\.complement$/, 'complement'],
  [/^shippingAddress\.divisions\.0(\.|$)/, 'state'],
  [/^shippingAddress\.divisions\.1(\.|$)/, 'municipality'],
  [/^shippingAddress\.divisions\.2(\.|$)/, 'neighborhood'],
  [/^items\.0\.quantity$/, 'quantity'],
];

/**
 * Commerce said no. The type of the problem (contracts/http/problems.md) tells apart what
 * a status alone cannot: a product out of line and a stock that ran out are both 409. A
 * 422 without a name is about the data, and its field names come back as the names of
 * the form, so each message lands next to the field a person has to fix.
 */
export function refusalError(refusal: Refusal): DomainError {
  switch (refusal.problem) {
    case 'product-unavailable':
      return new ProductOutOfLine('Esse produto');
    case 'idempotency-key-reused':
      return new FormAlreadyUsed();
    case 'stock-not-reserved':
      return new OrderNotPlaced();
  }
  if (refusal.status === 409) {
    return new OrderNotPlaced();
  }
  const fieldErrors: Record<string, string[]> = {};
  for (const field of refusal.fields) {
    const formField = FORM_FIELDS.find(([pattern]) => pattern.test(field))?.[1];
    if (formField !== undefined) {
      fieldErrors[formField] = ['Confira este campo.'];
    }
  }

  return new InvalidForm(
    fieldErrors,
    'Não consegui fechar o pedido com esses dados. Confira o e-mail e o endereço.',
  );
}
