import { CALM } from './chaos.ts';

// PayFake is as strict as a real PSP: unknown fields and wrong types answer 422,
// because app.ts turns off Ajv's removal of extra properties and its type coercion.

const MAX_LATENCY_MS = 30_000;
const MAX_WEBHOOK_DELAY_MS = 600_000;

const money = {
  type: 'object',
  additionalProperties: false,
  required: ['value', 'currency'],
  properties: {
    value: { type: 'integer', minimum: 1, maximum: Number.MAX_SAFE_INTEGER },
    currency: { type: 'string', pattern: '^[A-Z]{3}$' },
  },
} as const;

/** The merchant's own id for the payment: what a charge carries and what finds it again. */
const reference = { type: 'string', minLength: 1, maxLength: 128 } as const;

export const chargeRequestSchema = {
  type: 'object',
  additionalProperties: false,
  required: ['amount', 'cardToken', 'reference'],
  properties: {
    amount: money,
    cardToken: { type: 'string', pattern: '^tok_\\w{1,60}$' },
    reference,
  },
} as const;

export const refundRequestSchema = {
  type: 'object',
  additionalProperties: false,
  required: ['amount'],
  properties: { amount: money },
} as const;

const rate = { type: 'number', minimum: 0, maximum: 1, default: 0 } as const;

/**
 * A knob left out goes back to calm, so every PUT describes the whole experiment.
 * `max` points at `min` with Ajv's $data, the one comparison between fields.
 */
export const chaosSettingsSchema = {
  type: 'object',
  additionalProperties: false,
  properties: {
    latencyMs: {
      type: 'object',
      additionalProperties: false,
      required: ['min', 'max'],
      default: CALM.latencyMs,
      properties: {
        min: { type: 'integer', minimum: 0, maximum: MAX_LATENCY_MS },
        max: { type: 'integer', minimum: { $data: '1/min' }, maximum: MAX_LATENCY_MS },
      },
    },
    errorRate: rate,
    timeoutRate: rate,
    declineRate: rate,
    webhooks: {
      type: 'object',
      additionalProperties: false,
      default: {},
      properties: {
        dropRate: rate,
        duplicateRate: rate,
        delayMs: { type: 'integer', minimum: 0, maximum: MAX_WEBHOOK_DELAY_MS, default: 0 },
      },
    },
  },
} as const;

export const chargeLookupSchema = {
  type: 'object',
  additionalProperties: false,
  required: ['reference'],
  properties: { reference },
} as const;
