export type LogLine = Record<string, unknown>;

/** The log lines of chaos decisions, the ones an experiment is followed by. */
export function chaosDecisions(logs: readonly LogLine[]): LogLine[] {
  return logs.filter((line) => typeof line.chaos === 'string');
}
