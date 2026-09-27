import type { FastifyServerOptions } from 'fastify';
import type { Config } from '../config.ts';

/** Where log lines go: stdout unless a test passes a stream to read them back. */
export type LogStream = { write(line: string): void };

/**
 * One JSON object per line with the fields every service shares: timestamp, level,
 * service, message and, inside a request, correlation_id.
 */
export function logOptions(config: Config, stream?: LogStream): FastifyServerOptions['logger'] {
  return {
    level: config.logLevel,
    base: { service: config.serviceName },
    messageKey: 'message',
    timestamp: () => `,"timestamp":"${new Date().toISOString()}"`,
    formatters: { level: (label) => ({ level: label }) },
    stream,
  };
}

/** The only line written before Fastify and its logger exist: why the service refused to start. */
export function fatalLine(service: string, message: string): string {
  const line = { level: 'fatal', timestamp: new Date().toISOString(), service, message };

  return `${JSON.stringify(line)}\n`;
}
