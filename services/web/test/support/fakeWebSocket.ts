import { act } from '@testing-library/react';
import { vi } from 'vitest';

/**
 * A minimal stand-in for the browser `WebSocket`: just enough of its surface
 * (`onopen`/`onmessage`/`onerror`/`onclose`/`close`) for `LiveDelivery` to drive, with no real
 * socket or network I/O, so a test can script the server's side of the live-delivery protocol
 * by hand. Every call that can reach a React state update is wrapped in `act`.
 */
export class FakeWebSocket {
  readonly url: string;
  onopen: (() => void) | null = null;
  onmessage: ((event: { readonly data: unknown }) => void) | null = null;
  onerror: (() => void) | null = null;
  onclose: ((event: { readonly code: number }) => void) | null = null;
  closed = false;

  constructor(url: string) {
    this.url = url;
  }

  open(): void {
    act(() => this.onopen?.());
  }

  /** `data` is stringified if it is not already a string, so a test can pass a plain object. */
  message(data: unknown): void {
    const payload = typeof data === 'string' ? data : JSON.stringify(data);
    act(() => this.onmessage?.({ data: payload }));
  }

  error(): void {
    act(() => this.onerror?.());
  }

  /** The server's side of a close: tracking (or Kong, or a network blip) hanging up on us. */
  serverClose(code = 1000): void {
    act(() => this.onclose?.({ code }));
  }

  close(): void {
    this.closed = true;
  }
}

export type FakeSocketFactory = {
  readonly createSocket: (url: string) => WebSocket;
  /** Every socket `createSocket` has produced so far, oldest first: one per connect attempt. */
  readonly sockets: readonly FakeWebSocket[];
};

/** A `createSocket` factory for `LiveDelivery` a test can inject, recording every attempt. */
export function fakeSocketFactory(): FakeSocketFactory {
  const sockets: FakeWebSocket[] = [];
  return {
    sockets,
    createSocket: (url: string): WebSocket => {
      const socket = new FakeWebSocket(url);
      sockets.push(socket);
      return socket as unknown as WebSocket;
    },
  };
}

/**
 * Stubs the global `WebSocket` with one that never calls back: for a test that renders a whole
 * screen (and so gets `LiveDelivery`'s own default factory) without caring about the live card
 * itself. It just sits at "Conectando" for the life of the test, opening no real connection.
 */
export function stubInertGlobalWebSocket(): void {
  vi.stubGlobal(
    'WebSocket',
    class {
      onopen: (() => void) | null = null;
      onmessage: ((event: { readonly data: unknown }) => void) | null = null;
      onerror: (() => void) | null = null;
      onclose: ((event: { readonly code: number }) => void) | null = null;
      close(): void {}
    },
  );
}
