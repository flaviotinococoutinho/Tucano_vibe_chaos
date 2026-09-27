import { setTimeout as sleep } from 'node:timers/promises';

/** For what happens on a real socket, where no clock can be swapped: polls up to two seconds. */
export async function eventually(condition: () => boolean): Promise<void> {
  for (let tries = 1; !condition(); tries += 1) {
    if (tries > 200) {
      throw new Error('The condition did not hold within two seconds.');
    }
    await sleep(10);
  }
}
