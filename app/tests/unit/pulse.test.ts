import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPulse, reactToVoice, startPulse } from '@/lib/pulse';

afterEach(() => vi.unstubAllGlobals());

describe('the pulse', () => {
  it('carries a 🙏 and a ❤️ on the same wall request in one round', async () => {
    const bodies: { voices: unknown[] }[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init: RequestInit) => {
        bodies.push(JSON.parse(String(init.body)) as { voices: unknown[] });
        return new Response(JSON.stringify({ now: Date.now(), pulse: 120 }), { status: 200 });
      }),
    );
    const sent = async (n: number) => {
      for (let i = 0; i < 100 && bodies.length < n; i++) await new Promise((r) => setTimeout(r, 1));
    };
    startPulse('main', 120);
    await sent(1);
    reactToVoice('pk3v9q2m7x4tb', 'pray');
    reactToVoice('pk3v9q2m7x4tb', 'heart');
    flushPulse();
    await sent(2);
    expect(bodies[1]?.voices).toEqual([
      { voice: 'pk3v9q2m7x4tb', kind: 'pray' },
      { voice: 'pk3v9q2m7x4tb', kind: 'heart' },
    ]);
  });
});
