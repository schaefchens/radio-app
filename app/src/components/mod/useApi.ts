import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { modError } from './modApi';

/**
 * GET a /api path and keep the answer; `reload()` fetches again. The previous
 * answer stays visible while reloading, and an answer for another path is
 * never shown (state is keyed by path).
 */
export function useApi<T>(path: string | null): { data: T | null; error: string | null; reload: () => void } {
  const [state, setState] = useState<{ path: string | null; data: T | null; error: string | null }>({ path: null, data: null, error: null });
  const [version, setVersion] = useState(0);

  useEffect(() => {
    if (path === null) return;
    let live = true;
    api<T>(path).then(
      (data) => live && setState({ path, data, error: null }),
      (e: unknown) => live && setState((s) => ({ path, data: s.path === path ? s.data : null, error: modError(e) })),
    );
    return () => {
      live = false;
    };
  }, [path, version]);

  const reload = useCallback(() => setVersion((v) => v + 1), []);
  const current = state.path === path ? state : { data: null, error: null };
  return { data: current.data, error: current.error, reload };
}
