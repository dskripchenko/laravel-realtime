import type { WebSocketLike } from '../src/client.js';

/** In-memory WebSocket double: tests drive the server side by hand. */
export class FakeSocket implements WebSocketLike {
  static instances: FakeSocket[] = [];
  readyState = 0;
  sent: Record<string, unknown>[] = [];
  closedWith: { code: number | undefined; reason: string | undefined } | null = null;
  onopen: ((event: unknown) => void) | null = null;
  onmessage: ((event: { data: unknown }) => void) | null = null;
  onclose: ((event: { code: number; reason: string }) => void) | null = null;
  onerror: ((event: unknown) => void) | null = null;

  constructor(readonly url: string) {
    FakeSocket.instances.push(this);
  }

  static last(): FakeSocket {
    const socket = FakeSocket.instances[FakeSocket.instances.length - 1];
    if (!socket) throw new Error('no socket created');
    return socket;
  }

  send(data: string): void {
    this.sent.push(JSON.parse(data) as Record<string, unknown>);
  }

  close(code?: number, reason?: string): void {
    this.closedWith = { code, reason };
    this.readyState = 3;
  }

  /** Server accepts the ticket. */
  accept(socketId = '1.1', heartbeat = 25): void {
    this.readyState = 1;
    this.onopen?.({});
    this.push({ type: 'welcome', socket_id: socketId, heartbeat });
  }

  push(message: unknown): void {
    this.onmessage?.({ data: typeof message === 'string' ? message : JSON.stringify(message) });
  }

  drop(code = 1006): void {
    this.readyState = 3;
    this.onclose?.({ code, reason: '' });
  }

  sentOfType(type: string): Record<string, unknown>[] {
    return this.sent.filter((m) => m.type === type);
  }
}
