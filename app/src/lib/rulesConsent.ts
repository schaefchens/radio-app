import { RULES_VERSION } from '@/content/rules';
import { useSettings } from '@/store/settings';

/** Whether this device still has to accept the community rules before posting. */
export function useRulesNeeded(): boolean {
  return useSettings((s) => s.rules < RULES_VERSION);
}

export function acceptRules(): void {
  useSettings.getState().acceptRules(RULES_VERSION);
}
