/**
 * A thin wrapper around the YouTube IFrame Player API.
 *
 * One player for the whole session, created only after the listener agreed to
 * load YouTube (consent) and mounted into the stage region, where nothing is
 * ever layered on top of it (YouTube's required minimum functionality). The
 * privacy-enhanced host youtube-nocookie.com serves the player itself.
 */

interface YTPlayer {
  loadVideoById(o: { videoId: string; startSeconds?: number }): void;
  cueVideoById(o: { videoId: string; startSeconds?: number }): void;
  playVideo(): void;
  pauseVideo(): void;
  stopVideo(): void;
  seekTo(seconds: number, allowSeekAhead: boolean): void;
  getCurrentTime(): number;
  getDuration(): number;
  getPlayerState(): number;
  setVolume(v: number): void;
  mute(): void;
  unMute(): void;
  isMuted(): boolean;
  getVideoData?(): { video_id?: string };
  destroy(): void;
}

interface YTNamespace {
  Player: new (
    el: HTMLElement,
    opts: {
      host?: string;
      width?: string | number;
      height?: string | number;
      playerVars?: Record<string, string | number>;
      events?: {
        onReady?: () => void;
        onStateChange?: (e: { data: number }) => void;
        onError?: (e: { data: number }) => void;
      };
    },
  ) => YTPlayer;
}

declare global {
  interface Window {
    YT?: YTNamespace;
    onYouTubeIframeAPIReady?: () => void;
  }
}

export const YTState = { UNSTARTED: -1, ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3, CUED: 5 } as const;

let apiPromise: Promise<YTNamespace> | null = null;

function loadApi(): Promise<YTNamespace> {
  if (window.YT?.Player) return Promise.resolve(window.YT);
  if (apiPromise) return apiPromise;
  apiPromise = new Promise((resolve, reject) => {
    const previous = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => {
      previous?.();
      if (window.YT) resolve(window.YT);
    };
    const s = document.createElement('script');
    s.src = 'https://www.youtube.com/iframe_api';
    s.async = true;
    s.onerror = () => {
      apiPromise = null;
      reject(new Error('youtube_api_unavailable'));
    };
    document.head.appendChild(s);
  });
  return apiPromise;
}

export interface PlayerEvents {
  onState: (state: number) => void;
  onError: (code: number) => void;
}

export class YouTubePlayer {
  private player: YTPlayer | null = null;
  private readyResolve: (() => void) | null = null;
  readonly ready: Promise<void>;
  currentId: string | null = null;

  private readonly events: PlayerEvents;

  constructor(events: PlayerEvents) {
    this.events = events;
    this.ready = new Promise((r) => (this.readyResolve = r));
  }

  async mount(el: HTMLElement): Promise<void> {
    if (this.player) return;
    const YT = await loadApi();
    const target = document.createElement('div');
    el.replaceChildren(target);
    this.player = new YT.Player(target, {
      host: 'https://www.youtube-nocookie.com',
      width: '100%',
      height: '100%',
      playerVars: {
        controls: 0,
        disablekb: 1,
        playsinline: 1,
        rel: 0,
        iv_load_policy: 3,
        fs: 0,
        modestbranding: 1,
        enablejsapi: 1,
        origin: window.location.origin,
      },
      events: {
        onReady: () => this.readyResolve?.(),
        onStateChange: (e) => this.events.onState(e.data),
        onError: (e) => this.events.onError(e.data),
      },
    });
  }

  get mounted(): boolean {
    return this.player !== null;
  }

  load(videoId: string, startSeconds: number): void {
    this.currentId = videoId;
    this.player?.loadVideoById({ videoId, startSeconds: Math.max(0, startSeconds) });
  }

  cue(videoId: string, startSeconds: number): void {
    this.currentId = videoId;
    this.player?.cueVideoById({ videoId, startSeconds: Math.max(0, startSeconds) });
  }

  play(): void {
    this.player?.playVideo();
  }

  pause(): void {
    this.player?.pauseVideo();
  }

  stop(): void {
    this.currentId = null;
    try {
      this.player?.stopVideo();
    } catch {
      /* player not ready yet */
    }
  }

  seek(seconds: number): void {
    this.player?.seekTo(Math.max(0, seconds), true);
  }

  time(): number {
    try {
      return this.player?.getCurrentTime() ?? 0;
    } catch {
      return 0;
    }
  }

  state(): number {
    try {
      return this.player?.getPlayerState() ?? YTState.UNSTARTED;
    } catch {
      return YTState.UNSTARTED;
    }
  }

  setVolume(v: number): void {
    try {
      this.player?.setVolume(Math.round(Math.max(0, Math.min(1, v)) * 100));
      if (v > 0) this.player?.unMute();
    } catch {
      /* ignore */
    }
  }
}
