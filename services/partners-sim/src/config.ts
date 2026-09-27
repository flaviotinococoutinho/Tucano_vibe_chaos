const environments = ['local', 'staging', 'production'] as const;
const logLevels = ['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent'] as const;

export type Environment = (typeof environments)[number];
export type LogLevel = (typeof logLevels)[number];

export type Config = {
  readonly serviceName: string;
  readonly environment: Environment;
  readonly host: string;
  readonly port: number;
  readonly logLevel: LogLevel;
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

  const config: Config = {
    serviceName: text('SERVICE_NAME', 'partners-sim'),
    environment: environmentOf(text('APP_ENV', 'production')),
    host: text('HOST', '0.0.0.0'),
    port: port('PORT', 4000),
    logLevel: choice('LOG_LEVEL', logLevels, 'info'),
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
