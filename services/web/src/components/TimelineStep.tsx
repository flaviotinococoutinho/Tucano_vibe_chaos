import type { ReactElement } from 'react';
import { readInstant, readNumber, readString, type SirenSubEntity } from '../siren/index.ts';
import { InstantText } from './InstantText.tsx';
import './TimelineStep.css';

export type TimelineStepProps = {
  readonly entity: SirenSubEntity;
  /** The most recent step gets a filled marker instead of a hollow one. */
  readonly isCurrent: boolean;
};

/**
 * The `timeline-step` component. `label` already carries whatever the story needs to say
 * (a delivery attempt's reason included), so it is only ever shown as-is, never parsed.
 */
export function TimelineStep({ entity, isCurrent }: TimelineStepProps): ReactElement | null {
  const label = readString(entity.properties, 'label');
  const at = readInstant(entity.properties, 'at');
  const hub = readString(entity.properties, 'hub');
  const attempt = readNumber(entity.properties, 'attempt');

  if (label === undefined) {
    return null;
  }

  return (
    <li className={`timeline-step${isCurrent ? ' timeline-step--current' : ''}`}>
      <span className="timeline-step__marker" aria-hidden="true" />
      <p className="timeline-step__label">{label}</p>
      {at !== undefined ? (
        <p className="timeline-step__at">
          <InstantText value={at} />
        </p>
      ) : null}
      {hub !== undefined || attempt !== undefined ? (
        <p className="timeline-step__tags">
          {hub !== undefined ? <span className="timeline-step__tag">{hub}</span> : null}
          {attempt !== undefined ? (
            <span className="timeline-step__tag">Tentativa {attempt}</span>
          ) : null}
        </p>
      ) : null}
    </li>
  );
}
