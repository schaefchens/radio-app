import { describe, expect, it } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import '@/i18n';
import { ChannelSelect } from '@/components/mod/ChannelSelect';
import { useOverview } from '@/components/mod/overview';
import { useSettings } from '@/store/settings';
import type { Overview } from '@/components/mod/modApi';

describe('the channel picker on /mod', () => {
  it('renders while the overview is still loading — a fresh /mod/programs once re-rendered until React gave up', () => {
    useSettings.setState({ lang: 'en' });
    useOverview.setState({ data: null, error: null, channelId: null });
    const { container } = render(<ChannelSelect />);
    expect(container.innerHTML).toBe('');

    // When it arrives, two channels make a picker.
    const channel = (id: number, en: string) => ({ id, name_en: en, name_de: en, is_main: id === 1 ? 1 : 0, programs: [] });
    act(() => useOverview.setState({ data: { channels: [channel(1, 'Main'), channel(2, 'Prayer')] } as unknown as Overview }));
    expect(screen.getByRole('option', { name: 'Prayer' })).toBeTruthy();
  });
});
