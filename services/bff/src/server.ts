import { buildApp } from './app.ts';
import { type Config, InvalidConfig, loadConfig } from './config.ts';
import { fatalLine } from './platform/logging.ts';

const config = configOrExit();
const app = buildApp({ config });

for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.once(signal, () => void shutdown(signal));
}

try {
  await app.listen({ host: config.host, port: config.port });
} catch (error) {
  app.log.fatal({ err: error }, 'could not start listening');
  process.exit(1);
}

function configOrExit(): Config {
  try {
    return loadConfig(process.env);
  } catch (error) {
    if (!(error instanceof InvalidConfig)) {
      throw error;
    }
    process.stderr.write(fatalLine(error.service, error.message));
    process.exit(1);
  }
}

/** Stops taking connections, lets the requests in flight finish, then exits. */
async function shutdown(signal: NodeJS.Signals): Promise<void> {
  app.log.info({ signal }, 'shutting down');
  try {
    await app.close();
    process.exit(0);
  } catch (error) {
    app.log.error({ err: error }, 'could not shut down cleanly');
    process.exit(1);
  }
}
