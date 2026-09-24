/**
 * One JSON object per line on stdout — `docker compose logs` on a node is the
 * only place these are ever read. Never log a token, a secret or message text.
 */

export interface Logger {
  info(msg: string, extra?: Record<string, unknown>): void;
  warn(msg: string, extra?: Record<string, unknown>): void;
  error(msg: string, extra?: Record<string, unknown>): void;
}

export function createLogger(node: string, write: (line: string) => void = (l) => process.stdout.write(l + '\n')): Logger {
  const emit = (level: string, msg: string, extra?: Record<string, unknown>): void =>
    write(JSON.stringify({ t: new Date().toISOString(), level, node, msg, ...extra }));
  return {
    info: (msg, extra) => emit('info', msg, extra),
    warn: (msg, extra) => emit('warn', msg, extra),
    error: (msg, extra) => emit('error', msg, extra),
  };
}

export const silentLogger: Logger = { info: () => {}, warn: () => {}, error: () => {} };
