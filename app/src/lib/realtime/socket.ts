import type { ClientMsg, ServerMsg } from '@arche/shared';

/**
 * A thin WebSocket wrapper (after walk-in-the-spirit's net/socket.ts). Frames
 * are JSON. Reconnecting is the client's business, not the socket's: every
 * reconnect goes through the wake endpoint again, because the node may have
 * changed (draining, scale-in) or the token expired.
 */
export interface SocketHooks {
  onOpen: () => void;
  onMessage: (msg: ServerMsg) => void;
  onClose: (code: number) => void;
}

export type SocketFactory = (url: string) => WebSocket;

export class Socket {
  private ws: WebSocket | null = null;
  private closedByUs = false;
  private readonly url: string;
  private readonly hooks: SocketHooks;
  private readonly factory: SocketFactory;

  constructor(url: string, hooks: SocketHooks, factory: SocketFactory = (u) => new WebSocket(u)) {
    this.url = url;
    this.hooks = hooks;
    this.factory = factory;
  }

  open(): void {
    this.closedByUs = false;
    const ws = this.factory(this.url);
    this.ws = ws;
    ws.onopen = () => this.hooks.onOpen();
    ws.onmessage = (e: MessageEvent) => {
      try {
        this.hooks.onMessage(JSON.parse(String(e.data)) as ServerMsg);
      } catch {
        /* a malformed frame is ignored */
      }
    };
    ws.onclose = (e: CloseEvent) => {
      if (this.ws === ws) this.ws = null;
      if (!this.closedByUs) this.hooks.onClose(e.code);
    };
    ws.onerror = () => {
      try {
        ws.close();
      } catch {
        /* already closing */
      }
    };
  }

  send(msg: ClientMsg): boolean {
    const ws = this.ws;
    if (ws && ws.readyState === 1) {
      ws.send(JSON.stringify(msg));
      return true;
    }
    return false;
  }

  get connected(): boolean {
    return this.ws?.readyState === 1;
  }

  close(): void {
    this.closedByUs = true;
    try {
      this.ws?.close(1000, 'leave');
    } catch {
      /* ignore */
    }
    this.ws = null;
  }
}
