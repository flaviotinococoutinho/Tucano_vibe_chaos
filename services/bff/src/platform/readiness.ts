/** A dependency the service needs before it takes traffic. `check` rejects when it is not usable. */
export type HealthCheck = {
  readonly name: string;
  readonly check: () => Promise<void>;
};

export type CheckResult =
  | { readonly status: 'up'; readonly latencyMs: number }
  | { readonly status: 'down'; readonly latencyMs: number; readonly error: string };

export type ReadinessReport = {
  readonly status: 'up' | 'down';
  readonly checks: Readonly<Record<string, CheckResult>>;
};

/** Runs every check at once, so the probe takes as long as the slowest dependency, not the sum. */
export async function probe(checks: readonly HealthCheck[]): Promise<ReadinessReport> {
  const results = await Promise.all(
    checks.map(async (check) => [check.name, await run(check)] as const),
  );
  const healthy = results.every(([, result]) => result.status === 'up');

  return { status: healthy ? 'up' : 'down', checks: Object.fromEntries(results) };
}

async function run(check: HealthCheck): Promise<CheckResult> {
  const startedAt = performance.now();
  try {
    await check.check();

    return { status: 'up', latencyMs: elapsedSince(startedAt) };
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);

    return { status: 'down', latencyMs: elapsedSince(startedAt), error: reason };
  }
}

function elapsedSince(startedAt: number): number {
  return Math.floor(performance.now() - startedAt);
}
