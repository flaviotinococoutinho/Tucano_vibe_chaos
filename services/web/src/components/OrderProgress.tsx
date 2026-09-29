import type { ReactElement } from 'react';
import type { Milestone, MilestoneState } from '../siren/index.ts';
import { InstantText } from './InstantText.tsx';
import './OrderProgress.css';

/**
 * Said to a screen reader after each label, because the shape of a marker says it only to
 * the eyes. The current one also carries `aria-current="step"`, which not every reader announces.
 */
const STATE_WORDS: Readonly<Record<MilestoneState, string>> = {
  done: 'etapa concluída',
  current: 'etapa atual',
  upcoming: 'próxima etapa',
  stopped: 'o pedido parou aqui',
};

function Marker({ state }: { readonly state: MilestoneState }): ReactElement {
  return (
    <span className="order-progress__marker" aria-hidden="true">
      {state === 'done' ? (
        <svg viewBox="0 0 16 16" width="14" height="14" fill="none" aria-hidden="true">
          <path
            d="M3.5 8.5l3 3 6-7"
            stroke="currentColor"
            strokeWidth="2.25"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      ) : state === 'stopped' ? (
        <svg viewBox="0 0 16 16" width="12" height="12" fill="none" aria-hidden="true">
          <path
            d="M4 4l8 8M12 4l-8 8"
            stroke="currentColor"
            strokeWidth="2.25"
            strokeLinecap="round"
          />
        </svg>
      ) : state === 'current' ? (
        <span className="order-progress__dot" />
      ) : null}
    </span>
  );
}

/**
 * The milestones of an order, as an ordered list: done ones with a check, the current one
 * with a dot inside a ring, upcoming ones hollow, and a stopped one with an X. Across the
 * width on a wide screen, down the page on a narrow one.
 */
export function OrderProgress({
  progress,
}: {
  readonly progress: readonly Milestone[];
}): ReactElement {
  return (
    <ol className="order-progress" aria-label="Andamento do pedido">
      {progress.map((milestone, index) => (
        <li
          className={`order-progress__step order-progress__step--${milestone.state}`}
          aria-current={milestone.state === 'current' ? 'step' : undefined}
          // biome-ignore lint/suspicious/noArrayIndexKey: milestones come in a fixed order and carry no id.
          key={index}
        >
          <Marker state={milestone.state} />
          <span className="order-progress__text">
            <span className="order-progress__label">{milestone.label}</span>
            <span className="visually-hidden">, {STATE_WORDS[milestone.state]}</span>
            {milestone.at !== undefined ? (
              <span className="order-progress__at">
                <InstantText value={milestone.at} />
              </span>
            ) : null}
          </span>
        </li>
      ))}
    </ol>
  );
}
