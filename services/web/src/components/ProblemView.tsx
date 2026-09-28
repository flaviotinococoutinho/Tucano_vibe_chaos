import type { ReactElement } from 'react';
import type { Problem } from '../hypermedia/index.ts';
import { Illustration } from './Illustration.tsx';
import './ProblemView.css';

export type ProblemViewProps = {
  readonly problem: Problem;
  /** `page`: the screen itself failed to load. `inline`: a form submission failed. */
  readonly variant?: 'page' | 'inline';
};

const GENERIC_NETWORK_DETAIL =
  'Não foi possível falar com o servidor. Verifique sua conexão e tente de novo.';

/**
 * The body of a friendly problem: an illustration on `page`, the server's own `detail`
 * (or a generic one for a network failure), a `Retry-After` hint, and the correlation id to
 * quote when reporting it. The heading above it belongs to whoever places it (see
 * `screens/ScreenRouter`), so the same component fits both a full page and a form.
 */
export function ProblemView({ problem, variant = 'page' }: ProblemViewProps): ReactElement {
  const detail = problem.kind === 'http' ? problem.detail : GENERIC_NETWORK_DETAIL;
  const illustrationName =
    problem.kind === 'http' && problem.status === 404 ? 'not-found' : 'error';

  return (
    <div
      className={`problem-view problem-view--${variant}`}
      role={variant === 'inline' ? 'alert' : 'group'}
    >
      {variant === 'page' ? (
        <Illustration name={illustrationName} className="problem-view__illustration" />
      ) : null}
      <p className="problem-view__detail">{detail}</p>
      {problem.kind === 'http' && problem.retryAfterSeconds !== undefined ? (
        <p className="problem-view__hint">
          Tente novamente em {problem.retryAfterSeconds} segundos.
        </p>
      ) : null}
      {problem.kind === 'http' && problem.correlationId !== undefined ? (
        <p className="problem-view__correlation">
          Código de referência: <code className="numeric">{problem.correlationId}</code>
        </p>
      ) : null}
    </div>
  );
}
