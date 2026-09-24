import { afterEach, describe, expect, it } from 'vitest';
import { WebSocket } from 'ws';
import type { ServerMsg } from '@arche/shared';
import { CLOSE, Hub } from '../src/hub.ts';
import { createNodeServer, type NodeServer } from '../src/server.ts';
import { makeKeys, signToken, tokenPayload } from './helpers.ts';

/** End to end over real sockets: the one test that proves the wiring. */

const keys = makeKeys();
let node: NodeServer | null = null;

afterEach(async () => {
  await node?.close();
  node = null;
});

async function start(helloTimeoutMs = 5000): Promise<number> {
  const hub = new Hub({ tokenKey: keys.publicKey });
  node = createNodeServer({ hub, node: 'test', startedAt: 1, helloTimeoutMs });
  return node.listen(0, '127.0.0.1');
}

function open(port: number, path = '/ws'): Promise<{ ws: WebSocket; next: () => Promise<ServerMsg> }> {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}${path}`);
    const queue: ServerMsg[] = [];
    const waiters: ((m: ServerMsg) => void)[] = [];
    ws.on('message', (data) => {
      const msg = JSON.parse(String(data)) as ServerMsg;
      const w = waiters.shift();
      if (w) w(msg);
      else queue.push(msg);
    });
    const next = () =>
      new Promise<ServerMsg>((res) => {
        const m = queue.shift();
        if (m) res(m);
        else waiters.push(res);
      });
    ws.once('open', () => resolve({ ws, next }));
    ws.once('error', reject);
  });
}

describe('node server', () => {
  it('serves /health and 404s everything else', async () => {
    const port = await start();
    const health = await fetch(`http://127.0.0.1:${port}/health`);
    expect(await health.json()).toEqual({ ok: true, node: 'test', connections: 0, rooms: 0, draining: false, startedAt: 1 });
    expect((await fetch(`http://127.0.0.1:${port}/`)).status).toBe(404);
  });

  it('upgrades only /ws', async () => {
    const port = await start();
    await expect(open(port, '/other')).rejects.toThrow();
  });

  it('runs hello → welcome → chat over a real socket', async () => {
    const port = await start();
    const { ws, next } = await open(port);
    ws.send(JSON.stringify({ t: 'hello', token: signToken(keys.privateKey, tokenPayload()) }));
    expect((await next()).t).toBe('welcome');
    expect(await next()).toEqual({ t: 'presence', room: 1, channel: 1 });
    ws.send(JSON.stringify({ t: 'chat', text: 'Hallelujah', cid: 'c1' }));
    const msg = await next();
    expect(msg.t === 'msg' && msg.msg.text).toBe('Hallelujah');
    expect(msg.t === 'msg' && msg.msg.cid).toBe('c1');
    ws.close();
  });

  it('closes a socket that never says hello', async () => {
    const port = await start(50);
    const { ws, next } = await open(port);
    const closed = new Promise<number>((res) => ws.once('close', (code) => res(code)));
    expect(await next()).toEqual({ t: 'error', code: 'auth' });
    expect(await closed).toBe(CLOSE.auth);
  });
});
