const environments = ['local', 'staging', 'production'] as const;
const logLevels = ['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent'] as const;

// Ten minutes is plenty for a simulation and stays far from Node's timer limit
// (about 24 days), past which a timer fires at once.
const MAX_PROCESSING_DELAY_MS = 600_000;

export type Environment = (typeof environments)[number];
export type LogLevel = (typeof logLevels)[number];

export type PayFakeConfig = {
  /** Where webhooks go and the secret they are signed with. */
  readonly webhook: { readonly url: string; readonly secret: string };
  /** How long a charge or a refund stays processing before it settles. */
  readonly processingDelayMs: { readonly min: number; readonly max: number };
};

export type Config = {
  readonly serviceName: string;
  readonly environment: Environment;
  readonly host: string;
  readonly port: number;
  readonly logLevel: LogLevel;
  readonly payfake: PayFakeConfig;
};

type Env = Readonly<Record<string, string | undefined>>;

/** The service refuses to start with a value it cannot run with, and names every one of them. */
export class InvalidConfig extends Error {
  readonly service: string;

  constructor(service: string, problems: readonly string[]) {
    super(`Invalid configuration: ${problems.join('; ')}.`);
    this.name = 'InvalidConfig';
    this.service = service;
  }
}

/**
 * Reads the environment once, at startup. The defaults fit the compose stack,
 * and an empty variable counts as unset.
 */
export function loadConfig(env: Env): Config {
  const problems: string[] = [];

  const text = (name: string, fallback: string): string => env[name]?.trim() || fallback;

  const choice = <T extends string>(name: string, allowed: readonly T[], fallback: T): T => {
    const value = text(name, fallback);
    const normalized = value.toLowerCase();
    if (isOneOf(normalized, allowed)) {
      return normalized;
    }
    problems.push(`${name} must be one of ${allowed.join(', ')}, got "${value}"`);
    return fallback;
  };

  const port = (name: string, fallback: number): number => {
    const value = text(name, String(fallback));
    const parsed = Number(value);
    if (/^\d+$/.test(value) && parsed >= 1 && parsed <= 65535) {
      return parsed;
    }
    problems.push(`${name} must be an integer from 1 to 65535, got "${value}"`);
    return fallback;
  };

  const url = (name: string, fallback: string): string => {
    const value = text(name, fallback);
    const protocol = URL.parse(value)?.protocol;
    if (protocol === 'http:' || protocol === 'https:') {
      return value;
    }
    problems.push(`${name} must be an http or https URL, got "${value}"`);
    return fallback;
  };

  const milliseconds = (name: string, fallback: number): number => {
    const value = text(name, String(fallback));
    const parsed = Number(value);
    if (/^\d+$/.test(value) && parsed <= MAX_PROCESSING_DELAY_MS) {
      return parsed;
    }
    problems.push(
      `${name} must be an integer from 0 to ${MAX_PROCESSING_DELAY_MS}, got "${value}"`,
    );
    return fallback;
  };

  const processingMin = milliseconds('PAYFAKE_PROCESSING_MIN_MS', 300);
  const processingMax = milliseconds('PAYFAKE_PROCESSING_MAX_MS', 1500);
  if (processingMax < processingMin) {
    problems.push(
      `PAYFAKE_PROCESSING_MAX_MS must be at least PAYFAKE_PROCESSING_MIN_MS (${processingMin}), got "${processingMax}"`,
    );
  }

  const config: Config = {
    serviceName: text('SERVICE_NAME', 'partners-sim'),
    environment: environmentOf(text('APP_ENV', 'production')),
    host: text('HOST', '0.0.0.0'),
    port: port('PORT', 4000),
    logLevel: choice('LOG_LEVEL', logLevels, 'info'),
    payfake: {
      webhook: {
        url: url('PAYFAKE_WEBHOOK_URL', 'http://kong:8000/api/commerce/v1/webhooks/payfake'),
        secret: text('PAYFAKE_WEBHOOK_SECRET', 'whsec_local_payfake'),
      },
      processingDelayMs: { min: processingMin, max: processingMax },
    },
  };
  if (problems.length > 0) {
    throw new InvalidConfig(config.serviceName, problems);
  }

  return config;
}

/**
 * An environment this code does not know runs as production, the same rule the PHP
 * services follow: production is the side where chaos and lab flags stay off.
 */
function environmentOf(value: string): Environment {
  const normalized = value.toLowerCase();
  return isOneOf(normalized, environments) ? normalized : 'production';
}

function isOneOf<T extends string>(value: string, allowed: readonly T[]): value is T {
  return (allowed as readonly string[]).includes(value);
}
