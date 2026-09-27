// CarrierFake is as strict as a real carrier: unknown fields and wrong types answer 422,
// because app.ts turns off Ajv's removal of extra properties and its type coercion.

const MAX_WEBHOOK_DELAY_MS = 600_000;

const carrierCode = {
  type: 'string',
  enum: ['tucano-express', 'ligeirinho', 'correio-nacional', 'carga-pesada'],
} as const;

/** A Brazilian state (UF): two uppercase letters, not checked against the real 27. */
const state = { type: 'string', pattern: '^[A-Z]{2}$' } as const;

/** The merchant's own id for the shipment: what a pickup carries and what finds it again. */
const reference = { type: 'string', minLength: 1, maxLength: 128 } as const;

export const pickupRequestSchema = {
  type: 'object',
  additionalProperties: false,
  required: [
    'carrier',
    'reference',
    'trackingCode',
    'origin',
    'destination',
    'parcels',
    'weightGrams',
  ],
  properties: {
    carrier: carrierCode,
    reference,
    trackingCode: { type: 'string', pattern: '^TX[0-9A-HJKMNP-TV-Z]{13}$' },
    origin: {
      type: 'object',
      additionalProperties: false,
      required: ['center', 'state'],
      properties: {
        center: { type: 'string', pattern: '^[A-Z]{3}[0-9]$' },
        state,
      },
    },
    destination: {
      type: 'object',
      additionalProperties: false,
      required: ['city', 'state', 'postalCode'],
      properties: {
        city: { type: 'string', minLength: 1, maxLength: 120 },
        state,
        postalCode: { type: 'string', pattern: '^[0-9]{8}$' },
      },
    },
    parcels: { type: 'integer', minimum: 1, maximum: 99 },
    weightGrams: { type: 'integer', minimum: 1, maximum: 1_000_000 },
  },
} as const;

export const pickupLookupSchema = {
  type: 'object',
  additionalProperties: false,
  required: ['reference'],
  properties: { reference },
} as const;

const rate = { type: 'number', minimum: 0, maximum: 1, default: 0 } as const;

/** A knob left out goes back to calm, so every PUT describes the whole experiment. */
export const chaosSettingsSchema = {
  type: 'object',
  additionalProperties: false,
  properties: {
    failureRate: rate,
    refusalRate: rate,
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
