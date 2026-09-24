// A stand-in for the YouTube Data API v3 `videos.list` endpoint, for the e2e
// stack only (docker/e2e/compose.e2e.yaml runs it; the PHP side reaches it
// through YOUTUBE_API_BASE). The tests import VIDEOS to know what exists.
//
//   GET /youtube/v3/videos?part=snippet,contentDetails,status&id=<id>&key=<k>
//
// Plain JavaScript, no dependencies: it runs in a bare node:24-alpine image.

import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';

const song = (id, title, channel, duration, extra = {}) => ({
  id,
  title,
  channel,
  duration,
  embeddable: true,
  privacy: 'public',
  ageRestricted: false,
  ...extra,
});

/** Every video the fake knows. Ids are 11 characters, like YouTube's. */
export const VIDEOS = [
  // The library the setup curates.
  song('e2eSong0001', 'E2E Worship - Morning Light (Official Video)', 'E2E Worship', 'PT3M20S'),
  song('e2eSong0002', 'River of Grace - Hillside Choir', 'Hillside Choir', 'PT4M05S'),
  song('e2eSong0003', 'E2E Worship - Steady Heart', 'E2E Worship', 'PT3M02S'),
  song('e2eSong0004', 'Chorus of Nations - Open Doors (Live)', 'Chorus of Nations', 'PT2M48S'),
  song('e2eSong0005', 'Hillside Choir - Evening Hymn', 'Hillside Choir', 'PT3M37S'),
  song('e2eSong0006', 'Open Doors - Chorus of Nations', 'Chorus of Nations', 'PT3M11S'),
  // Song requests.
  song('e2eReqOk001', 'Hillside Choir - Carried (Lyric Video)', 'Hillside Choir', 'PT3M30S'),
  song('e2eReqLong1', 'E2E Worship - Ten Thousand Reasons Medley', 'E2E Worship', 'PT15M00S'),
  song('e2eNoEmbed1', 'Chorus of Nations - Unembeddable', 'Chorus of Nations', 'PT3M00S', { embeddable: false }),
];

export const LIBRARY_IDS = VIDEOS.filter((v) => v.id.startsWith('e2eSong')).map((v) => v.id);

function resource(v) {
  return {
    kind: 'youtube#video',
    id: v.id,
    snippet: {
      title: v.title,
      channelTitle: v.channel,
      description: `${v.title} — a fixture of the ARCHE e2e tests.`,
      tags: ['worship', 'e2e'],
      liveBroadcastContent: 'none',
    },
    contentDetails: { duration: v.duration, contentRating: v.ageRestricted ? { ytRating: 'ytAgeRestricted' } : {} },
    status: { embeddable: v.embeddable, privacyStatus: v.privacy, uploadStatus: 'processed' },
  };
}

function handle(req, res) {
  const url = new URL(req.url ?? '/', 'http://fakeyt');
  const send = (status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(body));
  };
  if (url.pathname === '/health') return send(200, { ok: true });
  if (req.method !== 'GET' || url.pathname !== '/youtube/v3/videos') return send(404, { error: { code: 404 } });
  if (!url.searchParams.get('key')) return send(403, { error: { code: 403, message: 'API key missing' } });
  const ids = (url.searchParams.get('id') ?? '').split(',').filter(Boolean);
  const items = VIDEOS.filter((v) => ids.includes(v.id)).map(resource);
  console.log(`videos.list ${ids.join(',')} → ${items.length}`);
  return send(200, { kind: 'youtube#videoListResponse', items, pageInfo: { totalResults: items.length } });
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const port = Number(process.env.PORT ?? 8080);
  createServer(handle).listen(port, () => console.log(`fake YouTube Data API on :${port}`));
}
