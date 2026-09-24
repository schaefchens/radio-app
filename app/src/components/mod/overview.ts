import { create } from 'zustand';
import { api } from '@/lib/api';
import { modError, type Overview } from './modApi';

/** Channels with their programs, counts, feature flags — shared by all /mod pages. */
interface OverviewState {
  data: Overview | null;
  error: string | null;
  /** Channel the Programs/Plans pages work on; null = the main channel. */
  channelId: number | null;
  load: () => Promise<void>;
  setChannel: (id: number) => void;
}

export const useOverview = create<OverviewState>((set) => ({
  data: null,
  error: null,
  channelId: null,
  load: async () => {
    try {
      set({ data: await api<Overview>('/mod/overview'), error: null });
    } catch (e) {
      set({ error: modError(e) });
    }
  },
  setChannel: (channelId) => set({ channelId }),
}));

/** The channel the page works on: the chosen one, else the main one. */
export function useModChannelId(): number | null {
  const data = useOverview((s) => s.data);
  const chosen = useOverview((s) => s.channelId);
  if (!data) return null;
  if (chosen !== null && data.channels.some((c) => c.id === chosen)) return chosen;
  return (data.channels.find((c) => Number(c.is_main) === 1) ?? data.channels[0])?.id ?? null;
}
