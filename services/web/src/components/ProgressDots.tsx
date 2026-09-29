import type { ReactElement } from 'react';
import type { Milestone } from '../siren/index.ts';
import './ProgressDots.css';

/**
 * The progress of an order in a glance, for a card of the list: one dot per milestone, each
 * state with its own shape. The dots say nothing to a screen reader; it hears the step the
 * order is in, and the badge next to them names it.
 */
export function ProgressDots({
  progress,
}: {
  readonly progress: readonly Milestone[];
}): ReactElement | null {
  if (progress.length === 0) {
    return null;
  }
  const reached = progress.findIndex(({ state }) => state === 'current' || state === 'stopped');
  const step = reached === -1 ? progress.length : reached + 1;

  return (
    <span className="progress-dots">
      <span className="progress-dots__dots" aria-hidden="true">
        {progress.map((milestone, index) => (
          <span
            className={`progress-dots__dot progress-dots__dot--${milestone.state}`}
            // biome-ignore lint/suspicious/noArrayIndexKey: milestones come in a fixed order and carry no id.
            key={index}
          />
        ))}
      </span>
      <span className="visually-hidden">
        Etapa {step} de {progress.length}
      </span>
    </span>
  );
}
