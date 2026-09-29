const environments = ['local', 'staging', 'production'] as const;
const logLevels = ['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent'] as const;

export type Environment = (typeof environments)[number];
export type LogLevel = (typeof logLevels)[number];

/** Where each service behind the BFF answers: base URLs without a trailing slash. */
export type Upstreams = {
  readonly catalog: string;
  readonly commerce: string;
  readonly logistics: string;
};

export type Config = {
  readonly serviceName: string;
  readonly environment: Environment;
  readonly host: string;
  readonly port: number;
  readonly logLevel: LogLevel;
  readonly upstreams: Upstreams;
  /** The whole exchange with a service, body included, before the BFF answers 503. */
  readonly upstreamTimeoutMs: number;
  /**
   * Where the web opens the live tracking WebSocket, on the public origin Kong serves
   * (never the BFF's own address): a path the tracking screen links to, not a route it answers.
   */
  readonly trackingLivePath: string;
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
    if (URL.canParse(value) && /^https?:$/.test(new URL(value).protocol)) {
      return value.replace(/\/+$/, '');
    }
    problems.push(`${name} must be an http or https URL, got "${value}"`);
    return fallback;
  };

  const integer = (name: string, fallback: number, min: number, max: number): number => {
    const value = text(name, String(fallback));
    const parsed = Number(value);
    if (/^\d+$/.test(value) && parsed >= min && parsed <= max) {
      return parsed;
    }
    problems.push(`${name} must be an integer from ${min} to ${max}, got "${value}"`);
    return fallback;
  };

  // Not a URL: Kong, not the BFF, answers it, so only the shape of a path matters here.
  const originPath = (name: string, fallback: string): string => {
    const value = text(name, fallback);
    if (value.startsWith('/')) {
      return value;
    }
    problems.push(`${name} must start with "/", got "${value}"`);
    return fallback;
  };

  // The defaults go through Toxiproxy, like every connection of the stack, so the chaos
  // reaches the door of the web too.
  const config: Config = {
    serviceName: text('APP_NAME', 'bff'),
    environment: environmentOf(text('APP_ENV', 'production')),
    host: text('HOST', '0.0.0.0'),
    port: port('PORT', 3000),
    logLevel: choice('LOG_LEVEL', logLevels, 'info'),
    upstreams: {
      catalog: url('CATALOG_URL', 'http://toxiproxy:18081'),
      commerce: url('COMMERCE_URL', 'http://toxiproxy:18082'),
      logistics: url('LOGISTICS_URL', 'http://toxiproxy:18083'),
    },
    upstreamTimeoutMs: integer('UPSTREAM_TIMEOUT_MS', 5000, 1, 60_000),
    trackingLivePath: originPath('TRACKING_LIVE_PATH', '/api/tracking/v1/live'),
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
