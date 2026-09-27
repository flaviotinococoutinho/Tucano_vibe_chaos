import { once } from 'node:events';
import { createServer, type IncomingHttpHeaders, type Server } from 'node:http';

export type ReceivedWebhook = { readonly headers: IncomingHttpHeaders; readonly body: string };

/** A status to answer with, or `hang-up` to drop the connection without an answer. */
export type Answer = number | 'hang-up';

/**
 * A real HTTP server in the place of commerce's webhook endpoint. It answers with the given
 * answers in order, then 200, and keeps every webhook it got.
 */
export class WebhookReceiver {
  readonly received: ReceivedWebhook[] = [];
  private readonly server: Server;
  private readonly answers: Answer[];
  private readonly waiting: { count: number; resolve: () => void }[] = [];

  private constructor(answers: readonly Answer[]) {
    this.answers = [...answers];
    this.server = createServer((request, response) => {
      const chunks: Buffer[] = [];
      request.on('data', (chunk: Buffer) => chunks.push(chunk));
      request.on('end', () => {
        const answer = this.answers.shift() ?? 200;
        if (answer === 'hang-up') {
          request.socket.destroy();
          return;
        }
        this.received.push({ headers: request.headers, body: Buffer.concat(chunks).toString() });
        response.writeHead(answer).end();
        this.wakeUpWaiting();
      });
    });
  }

  static async start(answers: readonly Answer[] = []): Promise<WebhookReceiver> {
    const receiver = new WebhookReceiver(answers);
    receiver.server.listen(0, '127.0.0.1');
    await once(receiver.server, 'listening');

    return receiver;
  }

  get url(): string {
    const address = this.server.address();
    if (address === null || typeof address === 'string') {
      throw new Error('The receiver is not listening on a TCP port.');
    }

    return `http://127.0.0.1:${address.port}/v1/webhooks/payfake`;
  }

  /** Resolves once `count` webhooks have arrived, counting the ones already here. */
  async waitFor(count: number): Promise<readonly ReceivedWebhook[]> {
    if (this.received.length < count) {
      await new Promise<void>((resolve) => this.waiting.push({ count, resolve }));
    }

    return this.received;
  }

  async close(): Promise<void> {
    this.server.closeAllConnections();
    this.server.close();
    await once(this.server, 'close');
  }

  private wakeUpWaiting(): void {
    for (const waiter of [...this.waiting]) {
      if (waiter.count <= this.received.length) {
        this.waiting.splice(this.waiting.indexOf(waiter), 1);
        waiter.resolve();
      }
    }
  }
}
