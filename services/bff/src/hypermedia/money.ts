/**
 * Money as the web gets it: the amount in minor units for whoever computes, and the
 * text for whoever reads, such as {"amount": 15990, "currency": "BRL", "formatted": "R$ 159,90"}.
 */
export type Money = {
  readonly amount: number;
  readonly currency: string;
  readonly formatted: string;
};

const formats = new Map<string, Intl.NumberFormat>();

export function money(amount: number, currency: string): Money {
  const format = formatOf(currency);
  // Minor units per currency: two for BRL, none for JPY. Intl knows each one (ISO 4217).
  const minorUnits = format.resolvedOptions().maximumFractionDigits ?? 2;

  return { amount, currency, formatted: format.format(amount / 10 ** minorUnits) };
}

/** The text keeps the no-break space Intl puts after the symbol, so R$ never ends a line alone. */
function formatOf(currency: string): Intl.NumberFormat {
  let format = formats.get(currency);
  if (format === undefined) {
    format = new Intl.NumberFormat('pt-BR', { style: 'currency', currency });
    formats.set(currency, format);
  }

  return format;
}
