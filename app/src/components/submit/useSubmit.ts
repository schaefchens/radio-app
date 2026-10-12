import { useState } from 'react';
import { ApiError } from '@/lib/api';
import { serverNow } from '@/lib/clock';
import { errorText, refusalText } from '@/i18n';

/** Submit state for a sheet: busy, done, or a translated error. */
export function useSubmit<T>(fn: () => Promise<T>): { busy: boolean; done: boolean; error: string | null; run: () => Promise<void>; reset: () => void } {
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return {
    busy,
    done,
    error,
    reset: () => {
      setDone(false);
      setError(null);
    },
    run: async () => {
      setBusy(true);
      setError(null);
      try {
        await fn();
        setDone(true);
      } catch (e) {
        setError(e instanceof ApiError ? refusalText(e.code, e.detail, serverNow()) : errorText('generic'));
      } finally {
        setBusy(false);
      }
    },
  };
}
