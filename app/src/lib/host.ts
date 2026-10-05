import type { ChannelInfo, HostInfo, TimelineItem } from '@arche/shared';
import type { EngineState } from './engine';

/** Before channels.json is in (or for a channel it does not list): who the station's host was. */
const NOBODY: HostInfo = { name: 'Hope', avatar: null, color: '#2f7bff', about: { en: '', de: '' }, voice: 'openai' };

/**
 * Who is speaking — or spoke last, while music plays: the host the moment
 * names; else (a file from before there were several hosts) the channel's.
 */
export function speakingHost(item: TimelineItem | null, lastHost: EngineState['lastHost'], channel: ChannelInfo | undefined): HostInfo {
  if (item?.type === 'host' && item.host) return item.host;
  if (item?.type !== 'host' && lastHost?.host) return lastHost.host;
  return channel?.host ?? NOBODY;
}
