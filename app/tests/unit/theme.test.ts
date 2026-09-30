import { describe, expect, it } from 'vitest';
import { resolveTheme, THEME, THEMES } from '@/lib/theme';

describe('theme', () => {
  it('follows the device until the listener picks one', () => {
    expect(resolveTheme(null, true)).toBe('dark');
    expect(resolveTheme(null, false)).toBe('light');
  });
  it('keeps the chosen theme whatever the device says', () => {
    expect(resolveTheme('light', true)).toBe('light');
    expect(resolveTheme('dark', false)).toBe('dark');
  });
  it('has art and a bar color for every theme', () => {
    for (const id of THEMES) {
      expect(THEME[id].art.top).toBeTruthy();
      expect(THEME[id].art.bottomMobile).toBeTruthy();
      expect(THEME[id].color).toMatch(/^#[0-9a-f]{6}$/);
    }
  });
});
