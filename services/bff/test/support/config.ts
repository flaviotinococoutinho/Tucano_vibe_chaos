import { type Config, loadConfig } from '../../src/config.ts';

/** The key the tests sign sessions with, long enough for the check of the configuration. */
export const TEST_SESSION_SECRET = 'a-session-secret-only-the-tests-use';

/** The configuration of a test: silent, with a session secret, and whatever the test adds. */
export function testConfig(env: Readonly<Record<string, string>> = {}): Config {
  return loadConfig({ LOG_LEVEL: 'silent', SESSION_SECRET: TEST_SESSION_SECRET, ...env });
}
