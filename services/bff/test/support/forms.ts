import { FORM_KEY } from './example-screens.ts';

/** The order form of the checkout of the Domain-Driven Design book, filled in as Ana would. */
export const orderForm = {
  idempotencyKey: FORM_KEY,
  sku: 'BOOK-DDD-001',
  quantity: 1,
  name: 'Ana Souza',
  email: 'ana@example.com',
  postalCode: '30160-011',
  thoroughfareType: 'Rua',
  thoroughfareName: 'da Bahia',
  number: '1200',
  complement: 'apto 42',
  neighborhood: 'Centro',
  municipality: 'Belo Horizonte',
  state: 'MG',
};
