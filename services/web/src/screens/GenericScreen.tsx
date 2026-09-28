import type { ReactElement } from 'react';
import { ActionForm, Badge, EmptyState, Link, Money, ProductCard } from '../components/index.ts';
import {
  findLink,
  isMoney,
  isTone,
  type SirenProperties,
  type SirenScreen,
  type SirenSubEntity,
} from '../siren/index.ts';
import './GenericScreen.css';

export type GenericScreenProps = { readonly screen: SirenScreen };

// Keys the shell already renders (the heading and the notice banner); the generic body skips them.
const HANDLED_PROPERTY_KEYS = new Set(['notice', 'refreshAfterSeconds']);

/**
 * The fallback renderer: properties, entities, links and actions, shown generically so a
 * screen class the web has no component for yet still works. A dedicated screen component
 * added to the registry later renders the same data with more care, not different data.
 */
export function GenericScreen({ screen }: GenericScreenProps): ReactElement {
  const entities = screen.entities ?? [];
  const links = (screen.links ?? []).filter((link) => !link.rel.includes('self'));
  const actions = screen.actions ?? [];

  return (
    <div className="generic-screen">
      <PropertyList properties={screen.properties} />
      {entities.length > 0 ? (
        <div className="generic-screen__entities">
          {entities.map((entity, index) => (
            // biome-ignore lint/suspicious/noArrayIndexKey: a sub-entity carries no id of its own.
            <div className="generic-screen__entity" key={index}>
              <GenericEntity entity={entity} />
            </div>
          ))}
        </div>
      ) : screen.entities !== undefined ? (
        <EmptyState title="Nada para mostrar aqui ainda." />
      ) : null}
      {links.length > 0 ? (
        <nav className="generic-screen__links" aria-label="Links da tela">
          <ul>
            {links.map((link) => (
              <li key={link.href}>
                <Link link={link} />
              </li>
            ))}
          </ul>
        </nav>
      ) : null}
      {actions.map((action) => (
        <ActionForm key={action.name} action={action} />
      ))}
    </div>
  );
}

/** `product-card` gets its real component; anything else, including a class the web has never seen, falls back to its properties. */
function GenericEntity({ entity }: { readonly entity: SirenSubEntity }): ReactElement {
  if (entity.class.includes('product-card')) {
    return <ProductCard entity={entity} />;
  }

  const self = findLink(entity.links, 'self');
  const body = <PropertyList properties={entity.properties} />;
  return self !== undefined ? (
    <Link link={self} className="generic-screen__entity-link">
      {body}
    </Link>
  ) : (
    (body ?? <p className="generic-screen__empty-value">-</p>)
  );
}

function PropertyList({
  properties,
}: {
  readonly properties: SirenProperties | undefined;
}): ReactElement | null {
  const entries = Object.entries(properties ?? {}).filter(
    ([key]) => !HANDLED_PROPERTY_KEYS.has(key),
  );
  if (entries.length === 0) {
    return null;
  }
  return (
    <dl className="generic-screen__properties">
      {entries.map(([key, value]) => (
        <div className="generic-screen__property" key={key}>
          <dt>{key}</dt>
          <dd>
            <PropertyValue value={value} />
          </dd>
        </div>
      ))}
    </dl>
  );
}

function PropertyValue({ value }: { readonly value: unknown }): ReactElement {
  if (isMoney(value)) {
    return <Money value={value} />;
  }
  if (isTone(value)) {
    return <Badge tone={value}>{value}</Badge>;
  }
  if (value === null || value === undefined) {
    return <span className="generic-screen__empty-value">-</span>;
  }
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return <span>{String(value)}</span>;
  }
  return <code>{JSON.stringify(value)}</code>;
}
