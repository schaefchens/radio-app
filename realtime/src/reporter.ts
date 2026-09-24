import { createHmac } from 'node:crypto';
import { REPORT_HEADERS, REPORT_INTERVAL_MS, type NodeConfig, type NodeReportResponse } from '@arche/shared';
import { sanitizeNodeConfig } from './config.ts';
import type { Hub } from './hub.ts';
import { silentLogger, type Logger } from './log.ts';

/**
 * The node's only line to PHP: POST /api/realtime/report every
 * REPORT_INTERVAL_MS. The report doubles as the heartbeat — PHP marks the node
 * ready on the first one and the reaper deletes a node that stops reporting —
 * and the response carries PHP's decisions back (bans, removals, config,
 * drain).
 *
 * Signature: hex(HMAC-SHA256(NODE_SECRET, `${unixSeconds}.${body}`)). PHP
 * rejects stale timestamps, so a captured report is worthless minutes later.
 */

export function signReport(secret: string, timestamp: number, body: string): string {
  return createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
}

export function reportHeaders(node: string, secret: string, timestamp: number, body: string): Record<string, string> {
  return {
    'Content-Type': 'application/json',
    [REPORT_HEADERS.node]: node,
    [REPORT_HEADERS.timestamp]: String(timestamp),
    [REPORT_HEADERS.signature]: signReport(secret, timestamp, body),
  };
}

const strings = (v: unknown): string[] =>
  Array.isArray(v) ? v.filter((s): s is string => typeof s === 'string' && s !== '') : [];

export function parseReportResponse(v: unknown, prev: NodeConfig): NodeReportResponse | null {
  if (typeof v !== 'object' || v === null || Array.isArray(v)) return null;
  const o = v as Record<string, unknown>;
  if (o.ok !== true) return null;
  return {
    ok: true,
    bans: strings(o.bans),
    removed: strings(o.removed),
    config: sanitizeNodeConfig(o.config, prev),
    drain: o.drain === true,
  };
}

export interface ReportLoopOptions {
  hub: Hub;
  node: string;
  apiBase: string;
  secret: string;
  startedAt: number;
  intervalMs?: number;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
  log?: Logger;
}

export class ReportLoop {
  private readonly opts: Required<Omit<ReportLoopOptions, 'log' | 'fetchImpl'>> & {
    log: Logger;
    fetchImpl: typeof fetch;
  };
  private timer: ReturnType<typeof setInterval> | null = null;
  private inFlight: Promise<boolean> | null = null;
  /** Failures are logged when the state changes, not on every attempt: an
   *  unreachable webhosting would otherwise write three lines a minute. */
  private failing = false;
  private warnedNoSecret = false;

  constructor(opts: ReportLoopOptions) {
    this.opts = {
      hub: opts.hub,
      node: opts.node,
      apiBase: opts.apiBase.replace(/\/+$/, ''),
      secret: opts.secret,
      startedAt: opts.startedAt,
      intervalMs: opts.intervalMs ?? REPORT_INTERVAL_MS,
      timeoutMs: opts.timeoutMs ?? 8000,
      fetchImpl: opts.fetchImpl ?? fetch,
      log: opts.log ?? silentLogger,
    };
  }

  start(): void {
    if (this.timer) return;
    void this.tick();
    this.timer = setInterval(() => void this.tick(), this.opts.intervalMs);
  }

  stop(): void {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
  }

  /** One report. A tick while the previous POST is still out is skipped — the
   *  deltas simply wait for the next one. */
  tick(): Promise<boolean> {
    if (this.inFlight) return Promise.resolve(false);
    this.inFlight = this.send().finally(() => {
      this.inFlight = null;
    });
    return this.inFlight;
  }

  /** Shutdown path: wait for a report in flight, then send what is left. */
  async flush(): Promise<boolean> {
    if (this.inFlight) await this.inFlight;
    return this.tick();
  }

  private async send(): Promise<boolean> {
    const { hub, node, apiBase, secret, startedAt, timeoutMs, fetchImpl, log } = this.opts;
    const { body, deltas } = hub.buildReport(node, startedAt);
    if (secret === '') {
      hub.agg.restore(deltas);
      if (!this.warnedNoSecret) log.warn('NODE_SECRET is not set; reports are not sent');
      this.warnedNoSecret = true;
      return false;
    }

    const json = JSON.stringify(body);
    const timestamp = Math.floor(Date.now() / 1000);
    let res: Response;
    try {
      res = await fetchImpl(`${apiBase}/api/realtime/report`, {
        method: 'POST',
        headers: reportHeaders(node, secret, timestamp, json),
        body: json,
        signal: AbortSignal.timeout(timeoutMs),
      });
    } catch (err) {
      // Unknown whether PHP processed it; re-sending may double count a few
      // reactions, which a trend can absorb. Losing a report of abuse cannot.
      hub.agg.restore(deltas);
      this.markFailing(`report failed: ${(err as Error).name}`);
      return false;
    }

    if (!res.ok) {
      hub.agg.restore(deltas);
      this.markFailing('report rejected', { status: res.status });
      return false;
    }

    let parsed: NodeReportResponse | null;
    try {
      parsed = parseReportResponse(await res.json(), hub.config);
    } catch {
      parsed = null;
    }
    if (parsed === null) {
      // Accepted (2xx) but unreadable: the deltas were delivered, so they are
      // not restored — only PHP's decisions are lost until the next report.
      this.markFailing('report response unreadable', { status: res.status });
      return false;
    }

    hub.applyResponse(parsed);
    if (this.failing) log.info('reports are getting through again');
    this.failing = false;
    return true;
  }

  private markFailing(msg: string, extra?: Record<string, unknown>): void {
    if (!this.failing) this.opts.log.warn(msg, extra);
    this.failing = true;
  }
}
