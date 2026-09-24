import { create } from 'zustand';
import type { ChannelsFile, Role } from '@arche/shared';
import { api } from '@/lib/api';
import { setCdnBase } from '@/lib/cdn';

export interface IdentityView {
  id: string;
  name: string;
  country: string;
  lang: 'en' | 'de';
  role: Role;
  claimed: boolean;
  banned: boolean;
}

export interface SessionConfig {
  pulse: number;
  langs: string[];
  realtime: boolean;
  setupNeeded: boolean;
  /** The CDN in front of /program and /media ('' = none; absent from an older server). */
  cdn?: string;
}

interface SessionState {
  identity: IdentityView | null;
  config: SessionConfig | null;
  channels: ChannelsFile | null;
  apiDown: boolean;
  setIdentity: (i: IdentityView | null) => void;
  setChannels: (c: ChannelsFile | null) => void;
  loadSession: () => Promise<number | null>;
}

export const useSession = create<SessionState>((set) => ({
  identity: null,
  config: null,
  channels: null,
  apiDown: false,
  setIdentity: (identity) => set({ identity }),
  setChannels: (channels) => set({ channels }),
  /** Returns the server time from the answer (for the clock), or null. */
  loadSession: async () => {
    try {
      const r = await api<{ now: number; identity: IdentityView | null; config: SessionConfig }>('/session', { body: {} });
      set({ identity: r.identity, config: r.config, apiDown: false });
      setCdnBase(r.config.cdn);
      return r.now;
    } catch {
      set({ apiDown: true });
      return null;
    }
  },
}));

export const isModerator = (i: IdentityView | null): boolean => !!i && i.claimed && (i.role === 'moderator' || i.role === 'admin');
