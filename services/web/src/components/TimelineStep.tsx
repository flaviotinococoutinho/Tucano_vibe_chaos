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
 * The `timeline-step` component. `label` says what happened and `detail`, when the step has
 * one, why (a cancellation reason, a visit nobody answered); both are shown as-is, never parsed.
 */
export function TimelineStep({ entity, isCurrent }: TimelineStepProps): ReactElement | null {
  const label = readString(entity.properties, 'label');
  const detail = readString(entity.properties, 'detail');
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
      {detail !== undefined ? <p className="timeline-step__detail">{detail}</p> : null}
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
