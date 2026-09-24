import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { WebSocketServer, type WebSocket } from 'ws';
import { CLOSE, type Conn, type Hub } from './hub.ts';
import { silentLogger, type Logger } from './log.ts';

/**
 * HTTP + WebSocket plumbing around the Hub. Two routes only: `GET /health` for
 * the wake controller and the snapshot smoke test, and the upgrade on `/ws`.
 * TLS is Caddy's job on a real node (infra/realtime/Caddyfile).
 */

export interface NodeServerOptions {
  hub: Hub;
  node: string;
  startedAt: number;
  helloTimeoutMs?: number;
  pingIntervalMs?: number;
  presenceIntervalMs?: number;
  sweepIntervalMs?: number;
  maxPayloadBytes?: number;
  log?: Logger;
}

export interface NodeServer {
  server: Server;
  wss: WebSocketServer;
  listen(port: number, host?: string): Promise<number>;
  close(): Promise<void>;
}

export function createNodeServer(opts: NodeServerOptions): NodeServer {
  const { hub, node, startedAt } = opts;
  const helloTimeoutMs = opts.helloTimeoutMs ?? 5000;
  const log = opts.log ?? silentLogger;

  const server = createServer((req: IncomingMessage, res: ServerResponse) => {
    const path = new URL(req.url ?? '/', 'http://node').pathname;
    if ((req.method === 'GET' || req.method === 'HEAD') && path === '/health') {
      const body = JSON.stringify(hub.health(node, startedAt));
      res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(req.method === 'HEAD' ? undefined : body);
      return;
    }
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end('{"ok":false}');
  });

  const wss = new WebSocketServer({ noServer: true, maxPayload: opts.maxPayloadBytes ?? 16 * 1024 });
  /** Liveness per socket: false after a ping went out, true once the pong is back. */
  const alive = new Map<WebSocket, boolean>();

  server.on('upgrade', (req, socket, head) => {
    const path = new URL(req.url ?? '/', 'http://node').pathname;
    if (path !== '/ws') {
      socket.write('HTTP/1.1 404 Not Found\r\nConnection: close\r\nContent-Length: 0\r\n\r\n');
      socket.destroy();
      return;
    }
    wss.handleUpgrade(req, socket, head, (ws) => wss.emit('connection', ws, req));
  });

  wss.on('connection', (ws: WebSocket) => {
    const conn: Conn = {
      send: (msg) => {
        if (ws.readyState === ws.OPEN) ws.send(JSON.stringify(msg));
      },
      close: (code, reason) => {
        try {
          ws.close(code, reason);
        } catch {
          ws.terminate();
        }
      },
    };
    const client = hub.connect(conn);
    alive.set(ws, true);

    const helloTimer = setTimeout(() => {
      if (client.token === null) {
        conn.send({ t: 'error', code: 'auth' });
        conn.close(CLOSE.auth, 'hello timeout');
      }
    }, helloTimeoutMs);

    ws.on('message', (data, isBinary) => {
      // Frames are JSON text; a binary frame is malformed by definition.
      hub.handleRaw(client, isBinary ? '' : data.toString());
    });
    ws.on('pong', () => alive.set(ws, true));
    ws.on('close', () => {
      clearTimeout(helloTimer);
      alive.delete(ws);
      hub.disconnect(client);
    });
    // Low-level socket errors are followed by `close`, which does the cleanup.
    ws.on('error', () => {});
  });

  // Protocol-level pings find sockets whose peer vanished without a FIN
  // (phone locked, network switched): presence would count them for ever.
  const ping = setInterval(() => {
    for (const [ws, ok] of alive) {
      if (!ok) {
        ws.terminate();
        continue;
      }
      alive.set(ws, false);
      ws.ping();
    }
  }, opts.pingIntervalMs ?? 30_000);
  const presence = setInterval(() => hub.presenceTick(), opts.presenceIntervalMs ?? 10_000);
  const sweep = setInterval(() => hub.sweep(), opts.sweepIntervalMs ?? 60_000);

  return {
    server,
    wss,
    listen(port, host = '0.0.0.0') {
      return new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(port, host, () => {
          server.off('error', reject);
          resolve((server.address() as AddressInfo).port);
        });
      });
    },
    async close() {
      clearInterval(ping);
      clearInterval(presence);
      clearInterval(sweep);
      for (const ws of alive.keys()) ws.terminate();
      await new Promise<void>((resolve) => wss.close(() => resolve()));
      await new Promise<void>((resolve) => server.close(() => resolve()));
      log.info('server closed');
    },
  };
}
