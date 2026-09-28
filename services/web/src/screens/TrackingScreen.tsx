import type { ReactElement } from 'react';
import { Badge, InstantText, TimelineStep } from '../components/index.ts';
import { entitiesOf, readInstant, readString, readTone, type SirenScreen } from '../siren/index.ts';
import './TrackingScreen.css';

type Destination = { readonly municipality: string; readonly state: string };

function readDestination(value: unknown): Destination | undefined {
  if (typeof value !== 'object' || value === null) {
    return undefined;
  }
  const { municipality, state } = value as Record<string, unknown>;
  return typeof municipality === 'string' && typeof state === 'string'
    ? { municipality, state }
    : undefined;
}

export function TrackingScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const statusLabel = readString(screen.properties, 'statusLabel');
  const tone = readTone(screen.properties, 'tone');
  // The BFF may only send the carrier's code (`carrier`); it shows the human label once it does.
  const carrierLabel =
    readString(screen.properties, 'carrierLabel') ?? readString(screen.properties, 'carrier');
  const destination = readDestination(screen.properties?.destination);
  const updatedAt = readInstant(screen.properties, 'updatedAt');
  const steps = entitiesOf(screen, 'item');

  return (
    <div className="tracking-screen">
      {statusLabel !== undefined && tone !== undefined ? (
        <p className="tracking-screen__status">
          <Badge tone={tone}>{statusLabel}</Badge>
        </p>
      ) : null}
      <dl className="tracking-screen__meta">
        {carrierLabel !== undefined ? (
          <div>
            <dt>Transportadora</dt>
            <dd>{carrierLabel}</dd>
          </div>
        ) : null}
        {destination !== undefined ? (
          <div>
            <dt>Destino</dt>
            <dd>
              {destination.municipality} - {destination.state}
            </dd>
          </div>
        ) : null}
        {updatedAt !== undefined ? (
          <div>
            <dt>Atualizado em</dt>
            <dd>
              <InstantText value={updatedAt} />
            </dd>
          </div>
        ) : null}
      </dl>
      {steps.length > 0 ? (
        <ol className="tracking-screen__timeline">
          {steps.map((entity, index) => (
            <TimelineStep
              entity={entity}
              isCurrent={index === steps.length - 1}
              // biome-ignore lint/suspicious/noArrayIndexKey: steps are an ordered history, not identified entities.
              key={index}
            />
          ))}
        </ol>
      ) : null}
    </div>
  );
}
