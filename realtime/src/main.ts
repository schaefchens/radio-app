import { loadEnv, readNodeEnv } from './config.ts';
import { CLOSE, Hub } from './hub.ts';
import { createLogger } from './log.ts';
import { ReportLoop } from './reporter.ts';
import { createNodeServer } from './server.ts';

/**
 * Process entry. Runs identically in `npm run stack` (compose service
 * `realtime`) and on a Hetzner node (infra/realtime/compose.node.yaml): same
 * image, configuration from the environment only.
 */

const bootWarnings: string[] = [];
const env = readNodeEnv(loadEnv(process.env, (m) => bootWarnings.push(m)));
const log = createLogger(env.slot);
for (const w of bootWarnings) log.warn(w);
if (env.tokenKey === null) log.warn('TOKEN_PUBLIC_KEY missing or invalid; every hello will be refused');

const startedAt = Date.now();
const hub = new Hub({ tokenKey: env.tokenKey, maxConnections: env.maxConnections, log });
const node = createNodeServer({ hub, node: env.slot, startedAt, log });
const reports = new ReportLoop({ hub, node: env.slot, apiBase: env.apiBase, secret: env.nodeSecret, startedAt, log });

const port = await node.listen(env.port);
log.info('listening', { port, apiBase: env.apiBase, publicHost: env.publicHost || null });
reports.start();

if (env.maxLifetimeMs !== null) {
  // A node that lives for days means the reaper is broken; draining makes the
  // clients move and lets the next wake start a fresh one.
  setTimeout(() => hub.startDrain('lifetime'), env.maxLifetimeMs).unref();
}

const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms).unref());

let stopping = false;
async function shutdown(signal: string): Promise<void> {
  if (stopping) return;
  stopping = true;
  log.info('shutting down', { signal });
  reports.stop();
  // Last report: whatever reactions and abuse reports are still in memory.
  await Promise.race([reports.flush(), sleep(3000)]);
  hub.closeAll(CLOSE.restart, 'restart');
  await Promise.race([node.close(), sleep(2000)]);
  process.exit(0);
}

process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
