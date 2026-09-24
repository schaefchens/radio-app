import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cdnUrl, fetchStatic, resetCdn, setCdnBase } from '@/lib/cdn';

const CDN = 'https://arche-radio.b-cdn.net';
const SLOT = '/program/main/slots/20260924/1200.json';

function answer(status: number): Response {
  return new Response(status === 200 ? '{}' : '', { status });
}

describe('cdn', () => {
  let fetchMock: ReturnType<typeof vi.fn>;
  beforeEach(() => {
    resetCdn();
    vi.useFakeTimers();
    vi.setSystemTime(1_790_244_000_000);
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('maps only our public files, and only when the session named a CDN', () => {
    expect(cdnUrl(SLOT)).toBe(SLOT);
    setCdnBase(`${CDN}/`);
    expect(cdnUrl(SLOT)).toBe(CDN + SLOT);
    expect(cdnUrl('/media/host/ab12.mp3')).toBe(`${CDN}/media/host/ab12.mp3`);
    expect(cdnUrl('/api/time')).toBe('/api/time');
    expect(cdnUrl('https://i.ytimg.com/vi/x/hqdefault.jpg')).toBe('https://i.ytimg.com/vi/x/hqdefault.jpg');
    setCdnBase('javascript:alert(1)');
    expect(cdnUrl(SLOT)).toBe(SLOT);
    setCdnBase('');
    expect(cdnUrl(SLOT)).toBe(SLOT);
  });

  it('reads from the edge; its 404 is the origin’s answer and final', async () => {
    setCdnBase(CDN);
    fetchMock.mockResolvedValueOnce(answer(200));
    const ok = await fetchStatic(SLOT, { cache: 'no-cache' });
    expect([ok.res.status, ok.fromOrigin]).toEqual([200, false]);
    expect(fetchMock.mock.calls[0]?.[0]).toBe(CDN + SLOT);
    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ cache: 'no-cache' });

    fetchMock.mockResolvedValueOnce(answer(404));
    const missing = await fetchStatic(SLOT);
    expect([missing.res.status, missing.fromOrigin]).toEqual([404, false]);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it.each([
    ['a network or CORS failure', () => Promise.reject(new TypeError('Failed to fetch'))],
    ['a suspended zone', () => Promise.resolve(answer(403))],
    ['an edge error', () => Promise.resolve(answer(503))],
  ])('falls back to the site on %s, and skips the edge for five minutes', async (_, edge) => {
    setCdnBase(CDN);
    fetchMock.mockImplementationOnce(edge).mockResolvedValueOnce(answer(200));
    const got = await fetchStatic(SLOT);
    expect([got.res.status, got.fromOrigin]).toEqual([200, true]);
    expect(fetchMock.mock.calls.map((c) => c[0])).toEqual([CDN + SLOT, SLOT]);

    fetchMock.mockResolvedValue(answer(200));
    await fetchStatic(SLOT);
    expect(fetchMock.mock.calls[2]?.[0]).toBe(SLOT);
    expect(cdnUrl('/media/stage/x.webp')).toBe('/media/stage/x.webp');

    vi.advanceTimersByTime(5 * 60_000);
    await fetchStatic(SLOT);
    expect(fetchMock.mock.calls[3]?.[0]).toBe(CDN + SLOT);
  });

  it('throws only when the site fails too; a new session config ends a pause', async () => {
    setCdnBase(CDN);
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
    await expect(fetchStatic(SLOT)).rejects.toThrow('Failed to fetch');
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(cdnUrl(SLOT)).toBe(SLOT);
    setCdnBase(CDN);
    expect(cdnUrl(SLOT)).toBe(CDN + SLOT);
  });
});
