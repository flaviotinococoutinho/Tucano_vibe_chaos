import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import Fastify from 'fastify';
import { loadConfig } from '../../src/config.ts';
import { logOptions } from '../../src/platform/logging.ts';
import {
  CALM_WEBHOOKS,
  planWebhook,
  type WebhookChaosSettings,
} from '../../src/webhooks/chaos-plan.ts';
import type { LogLine } from '../support/logs.ts';

const CONTEXT = { subjectId: 'thing_1', eventId: 'evt_1' };

function settingsFor(webhooks: Partial<WebhookChaosSettings>): WebhookChaosSettings {
  return { ...CALM_WEBHOOKS, ...webhooks };
}

function logging(): { logs: LogLine[]; log: ReturnType<typeof Fastify>['log'] } {
  const logs: LogLine[] = [];
  const logger = logOptions(loadConfig({ LOG_LEVEL: 'info' }), {
    write: (line) => logs.push(JSON.parse(line)),
  });

  return { logs, log: Fastify({ logger }).log };
}

describe('webhook chaos plan', () => {
  it('drops the event at the drop rate, and logs the caller context', () => {
    const { logs, log } = logging();

    const plan = planWebhook(() => 0.5, settingsFor({ dropRate: 0.6 }), log, CONTEXT);

    assert.deepEqual(plan, { fate: 'dropped' });
    assert.partialDeepStrictEqual(
      logs.find((line) => line.chaos === 'webhook-drop'),
      {
        level: 'info',
        chaos: 'webhook-drop',
        subjectId: 'thing_1',
        eventId: 'evt_1',
        message: 'chaos: dropping the webhook',
      },
    );
  });

  it('plans two copies at the duplicate rate, and logs it', () => {
    const { logs, log } = logging();

    const plan = planWebhook(() => 0.5, settingsFor({ duplicateRate: 0.6 }), log, CONTEXT);

    assert.deepEqual(plan, { fate: 'sent', copies: 2, delayMs: 0 });
    assert.partialDeepStrictEqual(
      logs.find((line) => line.chaos === 'webhook-duplicate'),
      { chaos: 'webhook-duplicate', subjectId: 'thing_1', eventId: 'evt_1' },
    );
  });

  it('plans the configured delay, and logs it', () => {
    const { logs, log } = logging();

    const plan = planWebhook(() => 0.5, settingsFor({ delayMs: 5_000 }), log, CONTEXT);

    assert.deepEqual(plan, { fate: 'sent', copies: 1, delayMs: 5_000 });
    assert.partialDeepStrictEqual(
      logs.find((line) => line.chaos === 'webhook-delay'),
      { chaos: 'webhook-delay', subjectId: 'thing_1', eventId: 'evt_1', delayMs: 5_000 },
    );
  });

  it('leaves a rate below the roll alone, and logs nothing', () => {
    const { logs, log } = logging();

    const plan = planWebhook(
      () => 0.5,
      settingsFor({ dropRate: 0.4, duplicateRate: 0.4 }),
      log,
      CONTEXT,
    );

    assert.deepEqual(plan, { fate: 'sent', copies: 1, delayMs: 0 });
    assert.deepEqual(logs, []);
  });
});
