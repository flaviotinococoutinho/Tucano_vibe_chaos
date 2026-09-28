import type { ReactElement } from 'react';
import { EmptyState, Link, ProductCard } from '../components/index.ts';
import { entitiesOf, findLink, readNumber, readString, type SirenScreen } from '../siren/index.ts';
import './CatalogScreen.css';

export function CatalogScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const products = entitiesOf(screen, 'item');
  const total = readNumber(screen.properties, 'total');
  const next = findLink(screen.links, 'next');
  const prev = findLink(screen.links, 'prev');

  if (products.length === 0) {
    return <EmptyState title="Nenhum produto no catálogo ainda." />;
  }

  return (
    <div className="catalog-screen">
      {total !== undefined ? <p className="catalog-screen__total">{total} produtos</p> : null}
      <div className="catalog-screen__grid">
        {products.map((entity, index) => (
          <ProductCard
            entity={entity}
            key={
              readString(entity.properties, 'sku') ?? findLink(entity.links, 'self')?.href ?? index
            }
          />
        ))}
      </div>
      {prev !== undefined || next !== undefined ? (
        <nav className="catalog-screen__pagination" aria-label="Paginação do catálogo">
          {prev !== undefined ? (
            <Link link={prev} className="button button--secondary">
              {prev.title ?? 'Anterior'}
            </Link>
          ) : (
            <span />
          )}
          {next !== undefined ? (
            <Link link={next} className="button button--secondary">
              {next.title ?? 'Próxima'}
            </Link>
          ) : null}
        </nav>
      ) : null}
    </div>
  );
}
