import type { ReactElement } from 'react';
import { ActionForm, Money } from '../components/index.ts';
import { findAction, readMoney, readNumber, readString, type SirenScreen } from '../siren/index.ts';
import './ProductScreen.css';

const CM_FORMAT = new Intl.NumberFormat('pt-BR', {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1,
});
const KG_FORMAT = new Intl.NumberFormat('pt-BR', {
  minimumFractionDigits: 1,
  maximumFractionDigits: 2,
});

type Dimensions = {
  readonly lengthMm: number;
  readonly widthMm: number;
  readonly heightMm: number;
};

function readDimensions(value: unknown): Dimensions | undefined {
  if (typeof value !== 'object' || value === null) {
    return undefined;
  }
  const { lengthMm, widthMm, heightMm } = value as Record<string, unknown>;
  return typeof lengthMm === 'number' && typeof widthMm === 'number' && typeof heightMm === 'number'
    ? { lengthMm, widthMm, heightMm }
    : undefined;
}

export function ProductScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const categoryLabel = readString(screen.properties, 'categoryLabel');
  const price = readMoney(screen.properties, 'price');
  const weightGrams = readNumber(screen.properties, 'weightGrams');
  const dimensions = readDimensions(screen.properties?.dimensions);
  const buy = findAction(screen.actions, 'buy');

  return (
    <div className="product-screen">
      {categoryLabel !== undefined ? (
        <p className="product-screen__category">{categoryLabel}</p>
      ) : null}
      {price !== undefined ? (
        <p className="product-screen__price">
          <Money value={price} />
        </p>
      ) : null}
      {weightGrams !== undefined || dimensions !== undefined ? (
        <dl className="product-screen__specs">
          {weightGrams !== undefined ? (
            <div>
              <dt>Peso</dt>
              <dd className="numeric">{KG_FORMAT.format(weightGrams / 1000)} kg</dd>
            </div>
          ) : null}
          {dimensions !== undefined ? (
            <div>
              <dt>Dimensões</dt>
              <dd className="numeric">
                {CM_FORMAT.format(dimensions.lengthMm / 10)} x{' '}
                {CM_FORMAT.format(dimensions.widthMm / 10)} x{' '}
                {CM_FORMAT.format(dimensions.heightMm / 10)} cm
              </dd>
            </div>
          ) : null}
        </dl>
      ) : null}
      {buy !== undefined ? <ActionForm action={buy} /> : null}
    </div>
  );
}
