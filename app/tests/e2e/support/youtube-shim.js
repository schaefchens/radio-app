/* global window, document, performance */
// A stand-in for the YouTube IFrame Player API (https://www.youtube.com/iframe_api),
// served to the page by fakeYouTube() in youtube.ts. It plays nothing: a
// "video" is a clock that starts at startSeconds when it begins to play, so a
// test can check what the app asked for and where the app thinks the song is.
// Every call lands in window.__yt.calls.
(() => {
  const S = { UNSTARTED: -1, ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3, CUED: 5 };
  const yt = (window.__yt = window.__yt || { calls: [], players: [] });
  const log = (fn, extra) => yt.calls.push(Object.assign({ fn, at: Date.now() }, extra));
  const args = (o, start) => (typeof o === 'string' ? { videoId: o, startSeconds: start } : o);

  class Player {
    constructor(target, opts) {
      const el = typeof target === 'string' ? document.getElementById(target) : target;
      // Like the real API: the target is replaced by the player's iframe.
      const frame = document.createElement('iframe');
      frame.setAttribute('data-fake-youtube', '');
      frame.title = 'YouTube video player (e2e fake)';
      frame.src = 'about:blank';
      frame.style.cssText = 'width:100%;height:100%;border:0;display:block;background:#000';
      el.replaceWith(frame);
      this.frame = frame;
      this.opts = opts || {};
      this.state = S.UNSTARTED;
      this.videoId = null;
      this.base = 0; // media time when the clock last started
      this.since = 0; // performance.now() at that moment; 0 = not running
      this.volume = 100;
      this.muted = false;
      this.timer = 0;
      yt.players.push(this);
      log('create', { host: this.opts.host, playerVars: this.opts.playerVars });
      setTimeout(() => this.opts.events && this.opts.events.onReady && this.opts.events.onReady({ target: this }), 30);
    }
    emit(state) {
      this.state = state;
      log('state', { state });
      if (this.opts.events && this.opts.events.onStateChange) this.opts.events.onStateChange({ data: state, target: this });
    }
    begin(videoId, startSeconds, autoplay) {
      clearTimeout(this.timer);
      this.videoId = videoId;
      this.base = Math.max(0, startSeconds || 0);
      this.since = 0;
      if (!autoplay) return this.emit(S.CUED);
      this.emit(S.BUFFERING);
      this.timer = setTimeout(() => {
        this.since = performance.now();
        this.emit(S.PLAYING);
      }, 250);
    }
    loadVideoById(o, start) {
      const { videoId, startSeconds } = args(o, start);
      log('load', { videoId, startSeconds });
      this.begin(videoId, startSeconds, true);
    }
    cueVideoById(o, start) {
      const { videoId, startSeconds } = args(o, start);
      log('cue', { videoId, startSeconds });
      this.begin(videoId, startSeconds, false);
    }
    playVideo() {
      log('play');
      if (this.videoId && this.state !== S.PLAYING) {
        this.since = performance.now();
        this.emit(S.PLAYING);
      }
    }
    pauseVideo() {
      log('pause');
      if (this.state === S.PLAYING) {
        this.base = this.getCurrentTime();
        this.since = 0;
        this.emit(S.PAUSED);
      }
    }
    stopVideo() {
      log('stop');
      clearTimeout(this.timer);
      this.base = 0;
      this.since = 0;
      if (this.state !== S.UNSTARTED) this.emit(S.UNSTARTED);
    }
    seekTo(seconds) {
      log('seek', { seconds });
      this.base = Math.max(0, seconds);
      if (this.since) this.since = performance.now();
    }
    getCurrentTime() {
      return this.since ? this.base + (performance.now() - this.since) / 1000 : this.base;
    }
    getDuration() {
      return 600;
    }
    getPlayerState() {
      return this.state;
    }
    setVolume(v) {
      this.volume = v;
    }
    getVolume() {
      return this.volume;
    }
    mute() {
      this.muted = true;
    }
    unMute() {
      this.muted = false;
    }
    isMuted() {
      return this.muted;
    }
    getVideoData() {
      return { video_id: this.videoId };
    }
    getIframe() {
      return this.frame;
    }
    destroy() {
      clearTimeout(this.timer);
      this.frame.remove();
    }
  }

  window.YT = { Player, PlayerState: S, loaded: 1 };
  setTimeout(() => window.onYouTubeIframeAPIReady && window.onYouTubeIframeAPIReady(), 0);
})();
